"""Geräte einzeln prüfen, mit nachvollziehbaren Fehlern pro Prüfschritt.

Jeder erfolgreich gelesene Befehl wird weiterhin unverändert dem
DataInterpreter übergeben. Ein Fehler bei einem einzelnen Befehl darf die
Ergebnisse der anderen Befehle nicht verlieren oder alte Incidents schließen.
"""

from concurrent.futures import ThreadPoolExecutor, as_completed
import sys
import traceback

from netmiko.exceptions import (
    NetmikoAuthenticationException,
    NetmikoTimeoutException,
)

from network_device import NetworkDevice
from data_interpreter import DataInterpreter
from device_metadata import extract_device_metadata


def _issue(command: str, code: str, message: str, details: str) -> dict:
    return {
        "command": command,
        "code": code,
        "message": message,
        "details": " ".join(str(details).split())[:600],
    }


def _diagnostic_error(command: str, code: str, title: str, error: Exception) -> dict:
    return _issue(command, code, title, f"{type(error).__name__}: {error}")


def _log_error(host: str, stage: str, error: Exception, credentials: list[dict]) -> None:
    """Traceback nur auf stderr; nie auf stdout im JSON-Protokoll.

    Zugangsdaten werden vorsichtshalber maskiert. PHP protokolliert stderr
    ebenfalls nur nach zusätzlicher Maskierung. Kein Passwort ans UI senden.
    """
    details = "".join(traceback.format_exception(type(error), error, error.__traceback__))
    details = f"[NetLab-Check] Gerät {host} / Schritt {stage}\n{details}"
    passwords = sorted(
        {str(c.get("password", "")) for c in credentials if c.get("password")},
        key=len, reverse=True,
    )
    for password in passwords:
        details = details.replace(password, "[Passwort verborgen]")
    print(details[:9000], file=sys.stderr, flush=True)


class DeviceChecker:
    @staticmethod
    def check(device: dict) -> dict:
        host = str(device.get("host", "unbekannt"))
        credentials = device.get("credentials", [])
        for credential in credentials:
            try:
                # Eine Verbindung pro Anmeldeversuch. Auch der in der Rack-JSON
                # eingestellte Port wird nun tatsächlich verwendet.
                with NetworkDevice(
                    host=host,
                    username=credential["username"],
                    password=credential["password"],
                    device_type=device["device_type"],
                    port=device.get("port", 23),
                ) as connection:
                    outputs: dict[str, str] = {}
                    command_errors: list[dict] = []
                    for command in device["commands"]:
                        try:
                            outputs[command] = connection.command(command)
                        except Exception as error:
                            # Nicht als 'evaluated' markieren: Nur ein
                            # erfolgreicher Befehl kann alte Fehler entwarnen.
                            _log_error(host, f"Befehl {command}", error, credentials)
                            command_errors.append(_diagnostic_error(
                                command,
                                "execution_failed",
                                "Cisco-Befehl konnte nicht ausgeführt werden",
                                error,
                            ))

                    # Inventardaten mit derselben Netmiko-Sitzung lesen. Diese
                    # Zusatzabfragen dürfen keine Diagnose-Incidents erzeugen,
                    # falls ein Modell den Befehl nicht unterstützt.
                    metadata_outputs = dict(outputs)
                    for metadata_command in ("show version", "show inventory"):
                        if metadata_command not in metadata_outputs:
                            try:
                                metadata_outputs[metadata_command] = connection.command(metadata_command)
                            except Exception:
                                # Optionaler Inventarbefehl, vorhandene Checks bleiben gültig.
                                pass
                    try:
                        prompt = connection.prompt()
                    except Exception:
                        prompt = ""
                    metadata = extract_device_metadata(metadata_outputs, prompt)
                    # Zusatzbefehle nur dann abfragen, wenn die Standardausgabe
                    # kein eindeutiges Chassis-Modell bzw. keine Seriennummer liefert.
                    # Fehlende Berechtigungen dürfen den eigentlichen Check nicht stören.
                    if not metadata.get("serial_number") and "show license udi" not in metadata_outputs:
                        try:
                            metadata_outputs["show license udi"] = connection.command("show license udi")
                            metadata = extract_device_metadata(metadata_outputs, prompt)
                        except Exception:
                            pass
                    if (not metadata.get("model") or not metadata.get("device_type")) and "show platform" not in metadata_outputs:
                        try:
                            metadata_outputs["show platform"] = connection.command("show platform")
                            metadata = extract_device_metadata(metadata_outputs, prompt)
                        except Exception:
                            pass

                    try:
                        diagnostics = DataInterpreter(outputs).interpret()
                    except Exception as error:
                        _log_error(host, "DataInterpreter", error, credentials)
                        return {
                            "status": "error",
                            "output": "Die Auswertung der Cisco-Ausgaben ist fehlgeschlagen",
                            "issues": [_diagnostic_error(
                                "_system", "interpreter_error",
                                "Interne Cisco-Auswertung fehlgeschlagen", error,
                            )],
                            "evaluated_commands": [],
                            "metadata": metadata,
                        }

                    if command_errors:
                        # Wenn alle Befehle fehlgeschlagen sind, ersetzt die
                        # konkrete Fehlermeldung die allgemeine 'keine Ausgabe'.
                        diagnostics["issues"] = [
                            issue for issue in diagnostics["issues"]
                            if not (issue["command"] == "_system"
                                    and issue["code"] == "no_diagnostic_output")
                        ] + command_errors
                        diagnostics["status"] = "error"
                        diagnostics["output"] = (
                            f"{len(diagnostics['issues'])} Auffälligkeit(en) oder "
                            "Befehlsfehler erkannt"
                        )
                    # Nur erkannte Geräteattribute, niemals CLI-Rohdaten oder
                    # Kennwörter an PHP zurückgeben.
                    diagnostics["metadata"] = metadata
                    return diagnostics

            except NetmikoAuthenticationException:
                # Beim nächsten hinterlegten Zugang erneut versuchen.
                continue
            except NetmikoTimeoutException:
                return {"status": "unreachable"}
            except Exception as error:
                _log_error(host, "Verbindungsaufbau oder Verbindungsende", error, credentials)
                return {
                    "status": "error",
                    "output": "Der Gerätezugriff ist fehlgeschlagen",
                    "issues": [_diagnostic_error(
                        "_transport", "connection_error",
                        "Geräteverbindung fehlgeschlagen", error,
                    )],
                    "evaluated_commands": [],
                }

        return {"status": "authentication_failed"}


class DevicePool:
    def __init__(self, workers: int = 20):
        self.workers = workers

    def run(self, devices: dict) -> dict:
        results = {}
        with ThreadPoolExecutor(max_workers=self.workers) as pool:
            futures = {
                pool.submit(DeviceChecker.check, device): (host, device)
                for host, device in devices.items()
            }
            for future in as_completed(futures):
                host, device = futures[future]
                try:
                    results[host] = future.result()
                except Exception as error:
                    # Unerwartete Fehler künftig immer im gleichen strukturierten
                    # Format ausgeben, statt PHP zu einer Legacy-Meldung zu zwingen.
                    _log_error(host, "DevicePool", error, device.get("credentials", []))
                    results[host] = {
                        "status": "error",
                        "output": "Die Geräteprüfung ist unerwartet fehlgeschlagen",
                        "issues": [_diagnostic_error(
                            "_transport", "python_exception",
                            "Python-Geräteprüfung fehlgeschlagen", error,
                        )],
                        "evaluated_commands": [],
                    }
        return results
