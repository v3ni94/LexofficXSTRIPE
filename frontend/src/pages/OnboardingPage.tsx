import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { api } from "../api/client";
import { useAuthStore } from "../stores/auth";

interface OnboardingStatus {
  completed: boolean;
  current_step: number;
  steps: {
    company_confirmed: boolean;
    lexoffice_connected: boolean;
    stripe_connected: boolean;
    first_sync_done: boolean;
  };
}

const STEP_LABELS = [
  "Willkommen",
  "Lexoffice",
  "Stripe",
  "Erster Sync",
  "Fertig",
];

function Stepper({ currentStep }: { currentStep: number }) {
  return (
    <div className="flex items-center justify-center gap-2 mb-8">
      {STEP_LABELS.map((label, i) => {
        const step = i + 1;
        const done = step <= currentStep;
        const active = step === currentStep + 1;
        return (
          <div key={i} className="flex items-center gap-2">
            {i > 0 && (
              <div
                className={`w-8 h-0.5 ${
                  step <= currentStep ? "bg-blue-500" : "bg-gray-300"
                }`}
              />
            )}
            <div className="flex flex-col items-center">
              <div
                className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium ${
                  done
                    ? "bg-blue-600 text-white"
                    : active
                    ? "bg-blue-100 text-blue-700 ring-2 ring-blue-500"
                    : "bg-gray-200 text-gray-500"
                }`}
              >
                {done ? "\u2713" : step}
              </div>
              <span className="text-[10px] text-gray-500 mt-1">{label}</span>
            </div>
          </div>
        );
      })}
    </div>
  );
}

export default function OnboardingPage() {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const [status, setStatus] = useState<OnboardingStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [stepLoading, setStepLoading] = useState(false);

  // Form state for steps
  const [lexKey, setLexKey] = useState("");
  const [stripeKey, setStripeKey] = useState("");
  const [webhookSecret, setWebhookSecret] = useState("");
  const [syncResult, setSyncResult] = useState<number | null>(null);

  const fetchStatus = async () => {
    try {
      const res = await api.get("/onboarding/status");
      setStatus(res.data);
      if (res.data.completed) {
        navigate("/dashboard", { replace: true });
      }
    } catch {
      setError("Fehler beim Laden des Onboarding-Status");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchStatus();
  }, []);

  const completeStep = async (step: number) => {
    setStepLoading(true);
    setError(null);
    try {
      const res = await api.put("/onboarding/complete-step", { step });
      if (step === 4) {
        setSyncResult(res.data.synced_count ?? 0);
      }
      await fetchStatus();
    } catch (err: unknown) {
      const detail =
        typeof err === "object" &&
        err !== null &&
        "response" in err
          ? ((err as { response: { data?: { detail?: string } } }).response
              .data?.detail ?? "Fehler")
          : "Verbindungsfehler";
      setError(detail);
    } finally {
      setStepLoading(false);
    }
  };

  const connectLexoffice = async () => {
    setStepLoading(true);
    setError(null);
    try {
      await api.put("/integrations/lexoffice", { api_key: lexKey });
      await completeStep(2);
    } catch (err: unknown) {
      const detail =
        typeof err === "object" &&
        err !== null &&
        "response" in err
          ? ((err as { response: { data?: { detail?: string } } }).response
              .data?.detail ?? "Verbindung fehlgeschlagen")
          : "Verbindungsfehler";
      setError(detail);
      setStepLoading(false);
    }
  };

  const connectStripe = async () => {
    setStepLoading(true);
    setError(null);
    try {
      await api.put("/integrations/stripe", {
        secret_key: stripeKey,
        webhook_secret: webhookSecret,
      });
      await completeStep(3);
    } catch (err: unknown) {
      const detail =
        typeof err === "object" &&
        err !== null &&
        "response" in err
          ? ((err as { response: { data?: { detail?: string } } }).response
              .data?.detail ?? "Verbindung fehlgeschlagen")
          : "Verbindungsfehler";
      setError(detail);
      setStepLoading(false);
    }
  };

  if (loading || !status) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="text-gray-500">Laden...</div>
      </div>
    );
  }

  const step = status.current_step;

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-6">
      <div className="w-full max-w-lg bg-white rounded-xl shadow-lg p-8">
        <Stepper currentStep={step} />

        {error && (
          <div className="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded text-sm">
            {error}
          </div>
        )}

        {/* Step 1: Welcome */}
        {step === 0 && (
          <div className="space-y-4">
            <h2 className="text-xl font-bold text-gray-900">
              Willkommen bei LexSEPA{user?.organization_name ? `, ${user.organization_name}` : ""}!
            </h2>
            <p className="text-gray-600 text-sm">
              LexSEPA verbindet dein Lexoffice mit Stripe fuer automatisierte SEPA-Lastschriften:
            </p>
            <ul className="text-sm text-gray-600 space-y-1 list-disc list-inside">
              <li>Offene Rechnungen automatisch synchronisieren</li>
              <li>SEPA-Lastschriften mit einem Klick einreichen</li>
              <li>Intelligente Verwendungszwecke</li>
              <li>Terminierte Einzuege planen</li>
            </ul>
            <button
              onClick={() => completeStep(1)}
              disabled={stepLoading}
              className="w-full mt-4 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
            >
              {stepLoading ? "..." : "Weiter"}
            </button>
          </div>
        )}

        {/* Step 2: Lexoffice */}
        {step === 1 && (
          <div className="space-y-4">
            <h2 className="text-xl font-bold text-gray-900">Lexoffice verbinden</h2>
            <div className="text-sm text-gray-600 space-y-2">
              <p>So findest du deinen API-Key:</p>
              <ol className="list-decimal list-inside space-y-1">
                <li>Oeffne <span className="font-mono text-xs">app.lexware.de/addons/public-api</span></li>
                <li>Klicke auf "Neuen Key erstellen"</li>
                <li>Kopiere den Key und fuege ihn hier ein</li>
              </ol>
              <p className="text-gray-400 text-xs mt-2">
                Du benoetigst den Lexoffice XL-Plan fuer den API-Zugang.
              </p>
            </div>
            <input
              type="text"
              value={lexKey}
              onChange={(e) => setLexKey(e.target.value)}
              placeholder="Lexoffice API-Key"
              className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <button
              onClick={connectLexoffice}
              disabled={stepLoading || !lexKey.trim()}
              className="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
            >
              {stepLoading ? "Verbindung wird getestet..." : "Verbindung testen"}
            </button>
          </div>
        )}

        {/* Step 3: Stripe */}
        {step === 2 && (
          <div className="space-y-4">
            <h2 className="text-xl font-bold text-gray-900">Stripe verbinden</h2>
            <div className="text-sm text-gray-600 space-y-2">
              <p>So richtest du Stripe ein:</p>
              <ol className="list-decimal list-inside space-y-1">
                <li>Oeffne <span className="font-mono text-xs">dashboard.stripe.com/apikeys</span></li>
                <li>Kopiere deinen Secret Key (sk_live_... oder sk_test_...)</li>
                <li>Erstelle einen Webhook unter <span className="font-mono text-xs">dashboard.stripe.com/webhooks</span></li>
                <li>Kopiere das Webhook-Secret (whsec_...)</li>
              </ol>
              <div className="mt-2 bg-gray-50 p-2 rounded text-xs">
                <span className="text-gray-500">Webhook-URL:</span>{" "}
                <span className="font-mono">{window.location.origin}/api/webhooks/stripe</span>
              </div>
            </div>
            <input
              type="text"
              value={stripeKey}
              onChange={(e) => setStripeKey(e.target.value)}
              placeholder="Stripe Secret Key (sk_...)"
              className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <input
              type="text"
              value={webhookSecret}
              onChange={(e) => setWebhookSecret(e.target.value)}
              placeholder="Webhook Secret (whsec_...)"
              className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <button
              onClick={connectStripe}
              disabled={stepLoading || !stripeKey.trim() || !webhookSecret.trim()}
              className="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
            >
              {stepLoading ? "Verbindung wird getestet..." : "Verbindung testen"}
            </button>
          </div>
        )}

        {/* Step 4: First sync */}
        {step === 3 && (
          <div className="space-y-4">
            <h2 className="text-xl font-bold text-gray-900">Erster Rechnungs-Sync</h2>
            {syncResult !== null ? (
              <div className="text-center space-y-3">
                <div className="text-4xl text-green-600">{syncResult}</div>
                <p className="text-gray-600">offene Rechnungen gefunden</p>
                <button
                  onClick={() => completeStep(5)}
                  disabled={stepLoading}
                  className="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                >
                  Weiter zum Dashboard
                </button>
              </div>
            ) : (
              <div className="text-center space-y-3">
                <p className="text-gray-600">
                  Wir laden jetzt deine offenen Rechnungen aus Lexoffice...
                </p>
                <button
                  onClick={() => completeStep(4)}
                  disabled={stepLoading}
                  className="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                >
                  {stepLoading ? (
                    <span className="flex items-center justify-center gap-2">
                      <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                      </svg>
                      Synchronisiere...
                    </span>
                  ) : (
                    "Sync starten"
                  )}
                </button>
              </div>
            )}
          </div>
        )}

        {/* Step 5: Done */}
        {step >= 4 && (
          <div className="space-y-4 text-center">
            <div className="text-4xl">&#10003;</div>
            <h2 className="text-xl font-bold text-gray-900">Alles eingerichtet!</h2>
            <p className="text-gray-600">
              Du kannst jetzt SEPA-Lastschriften einziehen.
            </p>
            <button
              onClick={() => {
                completeStep(5).then(() => navigate("/dashboard", { replace: true }));
              }}
              disabled={stepLoading}
              className="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
            >
              Zum Dashboard
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
