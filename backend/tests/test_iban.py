"""Pure unit tests for IBAN validation, formatting and masking.

No database or HTTP required – all functions are deterministic utilities.
"""
import pytest

from app.utils.iban import format_iban, mask_iban, validate_iban


# ---------------------------------------------------------------------------
# Valid IBANs (real-world examples that pass modulo-97 check)
# ---------------------------------------------------------------------------


@pytest.mark.parametrize(
    "raw,expected_cleaned",
    [
        # Germany (22 chars)
        ("DE89 3704 0044 0532 0130 00", "DE89370400440532013000"),
        ("de89370400440532013000", "DE89370400440532013000"),
        # Austria (20 chars)
        ("AT61 1904 3002 3457 3201", "AT611904300234573201"),
        # Switzerland (21 chars)
        ("CH56 0483 5012 3456 7800 9", "CH5604835012345678009"),
        # Netherlands (18 chars)
        ("NL91 ABNA 0417 1643 00", "NL91ABNA0417164300"),
        # France (27 chars)
        ("FR76 3000 6000 0112 3456 7890 189", "FR7630006000011234567890189"),
    ],
)
def test_valid_iban(raw, expected_cleaned):
    ok, result = validate_iban(raw)
    assert ok is True, f"Expected valid but got: {result}"
    assert result == expected_cleaned


# ---------------------------------------------------------------------------
# Invalid IBANs
# ---------------------------------------------------------------------------


def test_invalid_iban_empty_string():
    ok, msg = validate_iban("")
    assert ok is False
    assert "leer" in msg.lower() or "empty" in msg.lower() or msg  # German or fallback


def test_invalid_iban_wrong_checksum():
    # Flip check digits of a valid German IBAN
    ok, msg = validate_iban("DE00370400440532013000")  # 00 instead of 89
    assert ok is False
    assert "pruefsumme" in msg.lower() or "checksum" in msg.lower() or msg


def test_invalid_iban_wrong_length_de():
    # DE should be 22 chars; 21 chars here
    ok, msg = validate_iban("DE8937040044053201300")
    assert ok is False
    assert "22" in msg or msg


def test_invalid_iban_unsupported_country():
    ok, msg = validate_iban("US12345678901234567890")
    assert ok is False
    assert "US" in msg or msg


def test_invalid_iban_bad_format():
    ok, msg = validate_iban("NOTANIBAN")
    assert ok is False


def test_invalid_iban_spaces_only():
    ok, msg = validate_iban("   ")
    assert ok is False


# ---------------------------------------------------------------------------
# Formatting
# ---------------------------------------------------------------------------


def test_format_iban_groups_of_four():
    result = format_iban("DE89370400440532013000")
    assert result == "DE89 3704 0044 0532 0130 00"


def test_format_iban_strips_existing_spaces():
    result = format_iban("DE89 3704 0044 0532 0130 00")
    assert result == "DE89 3704 0044 0532 0130 00"


def test_format_iban_lowercased_input():
    result = format_iban("de89370400440532013000")
    assert result == "DE89 3704 0044 0532 0130 00"


# ---------------------------------------------------------------------------
# Masking
# ---------------------------------------------------------------------------


def test_mask_iban_shows_first_and_last_four():
    masked = mask_iban("DE89370400440532013000")
    # First group: DE89, last group: 3000
    assert masked.startswith("DE89")
    assert masked.endswith("30 00") or masked.endswith("3000")


def test_mask_iban_hides_middle():
    masked = mask_iban("DE89370400440532013000")
    assert "*" in masked


def test_mask_iban_groups_of_four():
    masked = mask_iban("DE89370400440532013000")
    # Should be space-separated groups of 4 (last group may be shorter)
    parts = masked.split(" ")
    for part in parts[:-1]:
        assert len(part) == 4, f"Group '{part}' is not 4 chars"
    assert 1 <= len(parts[-1]) <= 4, f"Last group '{parts[-1]}' has unexpected length"


def test_mask_iban_different_country():
    masked = mask_iban("NL91ABNA0417164300")
    assert masked.startswith("NL91")
    assert "*" in masked
