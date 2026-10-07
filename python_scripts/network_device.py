from netmiko import ConnectHandler


class NetworkDevice:
    def __init__(self, host, username, password, device_type="cisco_ios_telnet"):
        self.connection = ConnectHandler(
            device_type=device_type,
            host=host,
            username=username,
            password=password,
            port=23,
        )

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_value, traceback):
        self.close()

    def command(self, cmd):
        return self.connection.send_command(cmd)

    def commands(self, cmds):
        return self.connection.send_multiline(cmds)

    def close(self):
        self.connection.disconnect()