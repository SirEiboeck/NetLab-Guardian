class DataInterpreter:
    def __init__(self, data: dict[str, str]):
        self.data = data

    def interpret(self):
        return "ok", self.data