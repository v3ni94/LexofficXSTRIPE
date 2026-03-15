import re
from decimal import Decimal


KEYWORD_CATALOG = [
    {
        "display_name": "Vermietung",
        "sepa_name": "Vermietung",
        "search_terms": [
            "vermietung", "miete", "monatsmiete", "kaltmiete", "warmmiete",
            "mieteinnahme", "wohnungsmiete", "mietobjekt",
        ],
    },
    {
        "display_name": "Verkauf",
        "sepa_name": "Verkauf",
        "search_terms": [
            "verkauf", "kaufpreis", "veräußerung", "immobilienverkauf", "objektverkauf",
        ],
    },
    {
        "display_name": "Verwaltung",
        "sepa_name": "Verwaltung",
        "search_terms": [
            "verwaltung", "hausverwaltung", "objektverwaltung", "weg-verwaltung",
            "sondereigentumsverwaltung", "verwaltergebühr",
        ],
    },
    {
        "display_name": "Mieterhöhung",
        "sepa_name": "Mieterhoehung",
        "search_terms": [
            "mieterhöhung", "mietanpassung", "mietsteigerung", "staffelmiete", "indexmiete",
        ],
    },
    {
        "display_name": "Nebenkostenabrechnung",
        "sepa_name": "Nebenkostenabr.",
        "search_terms": [
            "nebenkosten", "betriebskosten", "betriebskostenabrechnung",
            "nebenkostenabrechnung", "hausgeld",
        ],
    },
    {
        "display_name": "Kaution",
        "sepa_name": "Kaution",
        "search_terms": [
            "kaution", "mietkaution", "sicherheitsleistung", "kautionseinbehalt",
        ],
    },
    {
        "display_name": "Provision",
        "sepa_name": "Provision",
        "search_terms": [
            "provision", "maklergebühr", "maklerprovision", "courtage", "vermittlungsprovision",
        ],
    },
    {
        "display_name": "Instandhaltung",
        "sepa_name": "Instandhaltung",
        "search_terms": [
            "instandhaltung", "reparatur", "sanierung", "renovierung",
            "modernisierung", "wartung", "instandsetzung",
        ],
    },
    {
        "display_name": "Sonstiges",
        "sepa_name": "Sonstiges",
        "search_terms": [],
    },
]


def sanitize_for_sepa(text: str) -> str:
    """Replace characters not allowed in SEPA Verwendungszweck."""
    replacements = {
        "ä": "ae", "ö": "oe", "ü": "ue", "ß": "ss",
        "Ä": "Ae", "Ö": "Oe", "Ü": "Ue",
        "é": "e", "è": "e", "ê": "e", "á": "a", "à": "a",
        "ñ": "n", "ç": "c",
    }
    for old, new in replacements.items():
        text = text.replace(old, new)
    # Only keep allowed SEPA characters: a-z A-Z 0-9 space / - ? : ( ) . , ' +
    text = re.sub(r"[^a-zA-Z0-9 /\-?:(). ,'+]", "", text)
    return text


class InvoiceKeywordService:
    def __init__(self, catalog: list[dict] | None = None):
        self.catalog = catalog or KEYWORD_CATALOG

    def extract_keyword(self, line_items: list[dict]) -> tuple[str, str]:
        """Analyse line items and return (display_name, sepa_name)."""
        matches: dict[str, Decimal] = {}  # display_name -> highest amount

        for item in line_items:
            item_text = (
                (item.get("name") or "") + " " + (item.get("description") or "")
            ).lower()

            amount = Decimal("0")
            total_price = item.get("totalPrice") or {}
            if total_price.get("totalGrossAmount") is not None:
                amount = Decimal(str(total_price["totalGrossAmount"]))

            for entry in self.catalog:
                if not entry["search_terms"]:
                    continue  # skip Sonstiges
                for term in entry["search_terms"]:
                    if re.search(rf"\b{re.escape(term.lower())}\b", item_text):
                        name = entry["display_name"]
                        if name not in matches or amount > matches[name]:
                            matches[name] = amount
                        break

        if not matches:
            return ("Sonstiges", "Sonstiges")

        if len(matches) == 1:
            name = list(matches.keys())[0]
            return (name, self._get_sepa_name(name))

        if len(matches) == 2:
            names = sorted(matches.keys())
            display = "/".join(names)
            sepa = "/".join(self._get_sepa_name(n) for n in names)
            return (display, sepa)

        # 3+ keywords: use the one with the highest amount
        top_name = max(matches, key=matches.get)
        return (top_name, self._get_sepa_name(top_name))

    def _get_sepa_name(self, display_name: str) -> str:
        for entry in self.catalog:
            if entry["display_name"] == display_name:
                return entry["sepa_name"]
        return "Sonstiges"

    def build_description(
        self, voucher_number: str, customer_number: str, keyword_sepa: str,
    ) -> str:
        """Build SEPA-compliant Verwendungszweck (max 140 chars)."""
        raw = f"SEPA LS RE {voucher_number} KD {customer_number} - {keyword_sepa}"
        sanitized = sanitize_for_sepa(raw)

        if len(sanitized) > 140:
            prefix = sanitize_for_sepa(
                f"SEPA LS RE {voucher_number} KD {customer_number} - "
            )
            max_kw_len = 140 - len(prefix) - 1
            keyword_truncated = sanitize_for_sepa(keyword_sepa)[:max_kw_len] + "."
            sanitized = prefix + keyword_truncated

        return sanitized[:140]
