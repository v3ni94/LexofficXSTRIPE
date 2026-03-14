import re


def validate_iban(iban: str) -> bool:
    """Validate an IBAN using the MOD-97 algorithm."""
    iban = iban.replace(" ", "").upper()

    if not re.match(r"^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$", iban):
        return False

    rearranged = iban[4:] + iban[:4]
    numeric = ""
    for char in rearranged:
        if char.isdigit():
            numeric += char
        else:
            numeric += str(ord(char) - 55)

    return int(numeric) % 97 == 1


def format_iban(iban: str) -> str:
    """Format IBAN into groups of 4 characters."""
    iban = iban.replace(" ", "").upper()
    return " ".join(iban[i : i + 4] for i in range(0, len(iban), 4))
