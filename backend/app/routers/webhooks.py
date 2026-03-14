from fastapi import APIRouter

router = APIRouter(prefix="/webhooks", tags=["webhooks"])

# TODO: Webhook endpoints for Stripe and Lexoffice callbacks
