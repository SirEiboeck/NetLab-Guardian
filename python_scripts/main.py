import sys
import json

from config_parser import Config
from device_checker import DevicePool


def main():
    config = Config(json.load(sys.stdin))

    result = DevicePool(workers=20).run(config.devices)

    json.dump(result, sys.stdout)


if __name__ == "__main__":
    main()