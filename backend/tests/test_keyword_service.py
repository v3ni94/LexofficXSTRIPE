"""Tests for InvoiceKeywordService and SEPA sanitization."""
import re

import pytest

from app.services.invoice_keyword_service import InvoiceKeywordService, sanitize_for_sepa


# ---------------------------------------------------------------------------
# SEPA sanitization
# ---------------------------------------------------------------------------


class TestSanitizeForSepa:
    def test_umlaut_replacement(self):
        assert sanitize_for_sepa("Mieterhöhung") == "Mieterhoehung"

    def test_sharp_s(self):
        assert sanitize_for_sepa("Straße 5") == "Strasse 5"

    def test_multiple_umlauts(self):
        result = sanitize_for_sepa("Ärger mit Übung")
        assert result == "Aerger mit Uebung"

    def test_accented_characters(self):
        assert sanitize_for_sepa("Café René") == "Cafe Rene"

    def test_illegal_characters_removed(self):
        result = sanitize_for_sepa("Test @#$& illegal")
        assert result == "Test  illegal"

    def test_normal_text_unchanged(self):
        assert sanitize_for_sepa("Normal text 123") == "Normal text 123"

    def test_allowed_special_chars(self):
        assert sanitize_for_sepa("Slash/Bindestrich-OK") == "Slash/Bindestrich-OK"

    def test_all_allowed_chars(self):
        allowed = "abcABC 123/-?:().,'+  "
        assert sanitize_for_sepa(allowed) == allowed

    def test_upper_umlauts(self):
        assert sanitize_for_sepa("ÄÖÜ") == "AeOeUe"


# ---------------------------------------------------------------------------
# Keyword extraction
# ---------------------------------------------------------------------------


class TestExtractKeyword:
    def setup_method(self):
        self.svc = InvoiceKeywordService()

    def test_single_keyword_vermietung(self):
        items = [
            {
                "name": "Monatsmiete Wohnung Kaiserstraße Köln",
                "description": "",
                "totalPrice": {"totalGrossAmount": 800},
            }
        ]
        assert self.svc.extract_keyword(items) == ("Vermietung", "Vermietung")

    def test_two_keywords_sorted_alphabetically(self):
        items = [
            {
                "name": "Maklergebühr Verkauf",
                "description": "",
                "totalPrice": {"totalGrossAmount": 500},
            },
            {
                "name": "Hausverwaltung Q1",
                "description": "",
                "totalPrice": {"totalGrossAmount": 200},
            },
        ]
        display, sepa = self.svc.extract_keyword(items)
        assert display == "Provision/Verkauf"
        assert sepa == "Provision/Verkauf"

    def test_three_keywords_highest_amount_wins(self):
        items = [
            {
                "name": "Verwaltung EG",
                "description": "",
                "totalPrice": {"totalGrossAmount": 200},
            },
            {
                "name": "Vermietung OG",
                "description": "",
                "totalPrice": {"totalGrossAmount": 800},
            },
            {
                "name": "Provision Makler",
                "description": "",
                "totalPrice": {"totalGrossAmount": 500},
            },
        ]
        assert self.svc.extract_keyword(items) == ("Vermietung", "Vermietung")

    def test_no_match_returns_sonstiges(self):
        items = [
            {
                "name": "Lieferung Büromaterial",
                "description": "",
                "totalPrice": {"totalGrossAmount": 50},
            }
        ]
        assert self.svc.extract_keyword(items) == ("Sonstiges", "Sonstiges")

    def test_empty_list_returns_sonstiges(self):
        assert self.svc.extract_keyword([]) == ("Sonstiges", "Sonstiges")

    def test_case_insensitive(self):
        items = [
            {
                "name": "NEBENKOSTENABRECHNUNG 2025",
                "description": "",
                "totalPrice": {"totalGrossAmount": 400},
            }
        ]
        assert self.svc.extract_keyword(items) == (
            "Nebenkostenabrechnung",
            "Nebenkostenabr.",
        )

    def test_umlaut_search_term(self):
        items = [
            {
                "name": "Indexmiete Anpassung",
                "description": "",
                "totalPrice": {"totalGrossAmount": 300},
            }
        ]
        assert self.svc.extract_keyword(items) == ("Mieterhöhung", "Mieterhoehung")

    def test_description_field_also_searched(self):
        items = [
            {
                "name": "Position 1",
                "description": "Kaution für Mietobjekt Berliner Str.",
                "totalPrice": {"totalGrossAmount": 2000},
            }
        ]
        assert self.svc.extract_keyword(items) == ("Kaution", "Kaution")

    def test_missing_fields_handled(self):
        items = [{"name": None, "description": None, "totalPrice": {}}]
        assert self.svc.extract_keyword(items) == ("Sonstiges", "Sonstiges")


# ---------------------------------------------------------------------------
# Build description
# ---------------------------------------------------------------------------


class TestBuildDescription:
    def setup_method(self):
        self.svc = InvoiceKeywordService()

    def test_basic_format(self):
        result = self.svc.build_description("RE260001", "10431", "Vermietung")
        assert result == "SEPA LS RE RE260001 KD 10431 - Vermietung"
        assert len(result) <= 140

    def test_combined_keyword(self):
        result = self.svc.build_description(
            "RE-2024-00001", "10001", "Instandhaltung/Nebenkostenabr."
        )
        assert "Instandhaltung/Nebenkostenabr." in result
        assert len(result) <= 140

    def test_no_umlauts_in_output(self):
        result = self.svc.build_description("RE001", "10001", "Mieterhoehung")
        assert not re.search(r"[äöüßÄÖÜ]", result)

    def test_only_sepa_chars(self):
        result = self.svc.build_description("RE-2024-00001", "10001", "Verkauf/Verwaltung")
        assert re.match(r"^[a-zA-Z0-9 /\-?:(). ,'+]+$", result)

    def test_truncation_at_140_chars(self):
        long_keyword = "A" * 200
        result = self.svc.build_description("RE001", "10001", long_keyword)
        assert len(result) <= 140

    def test_examples_from_spec(self):
        examples = [
            ("RE260001", "10431", "Vermietung", 43),
            ("RE-2024-00001", "10001", "Verkauf", 46),
            ("RE260012", "10431", "Verkauf/Verwaltung", 51),
            ("RE260099", "10001", "Nebenkostenabr.", 48),
            ("RE-2024-00001", "10001", "Instandhaltung/Nebenkostenabr.", 69),
        ]
        for voucher, customer, keyword, expected_len in examples:
            result = self.svc.build_description(voucher, customer, keyword)
            assert result.startswith("SEPA LS RE ")
            assert f"KD {customer}" in result
            assert len(result) == expected_len, f"Expected {expected_len} for {result!r}, got {len(result)}"
