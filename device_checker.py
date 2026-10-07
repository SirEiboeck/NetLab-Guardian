from concurrent.futures import ThreadPoolExecutor, as_completed

from netmiko.exceptions import (
    NetmikoAuthenticationException,
    NetmikoTimeoutException,
)

from network_device import NetworkDevice


class DeviceChecker:
    def check(self, device):
        for credential in device["credentials"]:
            try:
                with NetworkDevice(
                    device["host"],
                    device["device_type"],
                    credential,
                ) as connection:

                    output = connection.commands(device["commands"])

                    return {
                        "status": "ok",
                        "credential": credential["username"],
                        "output": output,
                    }

            except NetmikoAuthenticationException:
                continue

            except NetmikoTimeoutException:
                return {
                    "status": "unreachable",
                }

        return {
            "status": "authentication_failed",
        }


class DevicePool:
    def __init__(self, workers=20):
        self.workers = workers
        self.checker = DeviceChecker()

    def run(self, devices):
        results = {}

        with ThreadPoolExecutor(max_workers=self.workers) as pool:
            futures = {
                pool.submit(self.checker.check, device): host
                for host, device in devices.items()
            }

            for future in as_completed(futures):
                host = futures[future]

                try:
                    results[host] = future.result()
                except Exception as error:
                    results[host] = {
                        "status": "error",
                        "error": str(error),
                    }

        return results