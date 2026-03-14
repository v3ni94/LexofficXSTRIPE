import re

# Expected IBAN lengths per country code (ISO 13616)
IBAN_LENGTHS: dict[str, int] = {
    "DE": 22,
    "AT": 20,
    "CH": 21,
    "NL": 18,
    "FR": 27,
    "BE": 16,
    "LU": 20,
    "IT": 27,
    "ES": 24,
}


def validate_iban(iban: str) -> tuple[bool, str]:
    """Validate an IBAN. Returns (True, cleaned_iban) or (False, error_message)."""
    # Clean: remove whitespace, dashes, uppercase
    cleaned = re.sub(r"[\s\-]", "", iban).upper()

    if not cleaned:
        return False, "IBAN darf nicht leer sein"

    # Basic format: 2 letters + 2 digits + alphanumeric
    if not re.match(r"^[A-Z]{2}\d{2}[A-Z0-9]+$", cleaned):
        return False, "IBAN hat ein ungueltiges Format"

    country = cleaned[:2]

    # Country-specific length check
    expected_len = IBAN_LENGTHS.get(country)
    if expected_len is None:
        return False, f"Laendercode '{country}' wird nicht unterstuetzt"

    if len(cleaned) != expected_len:
        return (
            False,
            f"IBAN fuer {country} muss {expected_len} Zeichen haben, hat aber {len(cleaned)}",
        )

    # Modulo 97 check (ISO 13616)
    rearranged = cleaned[4:] + cleaned[:4]
    numeric = ""
    for char in rearranged:
        if char.isdigit():
            numeric += char
        else:
            numeric += str(ord(char) - 55)

    if int(numeric) % 97 != 1:
        return False, "IBAN-Pruefsumme ist ungueltig"

    return True, cleaned


def format_iban(iban: str) -> str:
    """Format IBAN into groups of 4: 'DE89 3704 0044 0532 0130 00'."""
    cleaned = re.sub(r"[\s\-]", "", iban).upper()
    return " ".join(cleaned[i : i + 4] for i in range(0, len(cleaned), 4))


def mask_iban(iban: str) -> str:
    """Mask IBAN: show first 4 + last 4, rest as stars in 4-groups.
    'DE89 **** **** **** **30 00'
    """
    cleaned = re.sub(r"[\s\-]", "", iban).upper()
    if len(cleaned) <= 8:
        return format_iban(cleaned)

    first4 = cleaned[:4]
    last4 = cleaned[-4:]
    middle_len = len(cleaned) - 8
    masked = first4 + "*" * middle_len + last4

    return " ".join(masked[i : i + 4] for i in range(0, len(masked), 4))
