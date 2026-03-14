import stripe


class StripeService:
    def __init__(self, secret_key: str):
        stripe.api_key = secret_key
        self._secret_key = secret_key

    def find_or_create_customer(
        self,
        name: str,
        email: str | None,
        metadata: dict,
    ) -> stripe.Customer:
        tenant_id = metadata.get("tenant_id", "")
        customer_id = metadata.get("customer_id", "")

        # Search for existing customer
        results = stripe.Customer.search(
            query=f"metadata['tenant_id']:'{tenant_id}' AND metadata['customer_id']:'{customer_id}'",
            api_key=self._secret_key,
        )
        if results.data:
            return results.data[0]

        # Create new customer
        create_params: dict = {
            "name": name,
            "metadata": metadata,
            "api_key": self._secret_key,
        }
        if email:
            create_params["email"] = email

        return stripe.Customer.create(**create_params)

    def create_sepa_payment_method(
        self,
        iban: str,
        name: str,
        email: str,
    ) -> stripe.PaymentMethod:
        return stripe.PaymentMethod.create(
            type="sepa_debit",
            sepa_debit={"iban": iban},
            billing_details={"name": name, "email": email},
            api_key=self._secret_key,
        )

    def attach_payment_method(
        self,
        payment_method_id: str,
        customer_id: str,
    ) -> stripe.PaymentMethod:
        return stripe.PaymentMethod.attach(
            payment_method_id,
            customer=customer_id,
            api_key=self._secret_key,
        )

    def create_payment_intent(
        self,
        amount_cents: int,
        customer_id: str,
        payment_method_id: str,
        mandate_reference: str,
        description: str,
        metadata: dict,
        contact_email: str,
    ) -> stripe.PaymentIntent:
        return stripe.PaymentIntent.create(
            amount=amount_cents,
            currency="eur",
            customer=customer_id,
            payment_method=payment_method_id,
            payment_method_types=["sepa_debit"],
            confirm=True,
            mandate_data={
                "customer_acceptance": {
                    "type": "offline",
                    "offline": {"contact_email": contact_email},
                }
            },
            description=description,
            metadata=metadata,
            api_key=self._secret_key,
        )
