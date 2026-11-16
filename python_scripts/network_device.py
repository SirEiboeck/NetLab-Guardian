"""Kleine Netmiko-Schnittstelle für den DeviceChecker."""

from netmiko import ConnectHandler


class NetworkDevice:
    def __init__(self, host, username, password, device_type="cisco_ios_telnet", port=23):
        self.connection = ConnectHandler(
            device_type=device_type,
            host=host,
            username=username,
            password=password,
            port=port,
        )

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_value, traceback):
        try:
            self.close()
        except Exception:
            # Ein Disconnect-Fehler ist kein Fehler der bereits gelesenen
            # Cisco-Ausgaben und darf deren Ergebnis nicht verwerfen.
            pass

    def command(self, cmd):
        return self.connection.send_command(cmd)

    def commands(self, cmds):
        # Kompatibel mit bisherigen Aufrufern.
        return {cmd: self.command(cmd) for cmd in cmds}

    def close(self):
        self.connection.disconnect()
