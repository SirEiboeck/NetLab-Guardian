from ipaddress import ip_address


class Config:
    def __init__(self, data):
        self.data = data
        self.defaults = data["defaults"]

        self.credentials = {
            credential["username"]: credential
            for credential in data["credentials"]
        }

    @property
    def devices(self):
        return {
            host: self._device(host, config)
            for host, config in self.data["devices"].items()
        }

    def _device(self, host, config):
        credential_names = config.get("credentials")

        credentials = (
            [self.credentials[name] for name in credential_names]
            if credential_names
            else list(self.credentials.values())
        )

        return {
            "host": host,
            "device_type": config.get(
                "device_type",
                self.defaults["device_type"],
            ),
            "commands": config.get(
                "commands",
                self.defaults["commands"],
            ),
            "credentials": credentials,
        }

    @staticmethod
    def ip_range(start, end):
        start = int(ip_address(start))
        end = int(ip_address(end))

        for ip in range(start, end + 1):
            yield str(ip_address(ip))