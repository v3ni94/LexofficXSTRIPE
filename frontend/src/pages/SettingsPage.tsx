import { useEffect, useState } from "react";
import { useIntegrationsStore } from "../stores/integrations";
import { usePermission } from "../hooks/usePermission";

function CopyButton({ text }: { text: string }) {
  const [copied, setCopied] = useState(false);

  const handleCopy = async () => {
    await navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <button
      onClick={handleCopy}
      className="ml-2 px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded hover:bg-gray-200 transition-colors shrink-0"
    >
      {copied ? "Kopiert!" : "Kopieren"}
    </button>
  );
}

export default function SettingsPage() {
  const { canManageIntegrations } = usePermission();
  const { status, isLoading, fetchStatus } = useIntegrationsStore();
  const connectLexoffice = useIntegrationsStore((s) => s.connectLexoffice);
  const connectStripe = useIntegrationsStore((s) => s.connectStripe);
  const disconnectLexoffice = useIntegrationsStore(
    (s) => s.disconnectLexoffice
  );
  const disconnectStripe = useIntegrationsStore((s) => s.disconnectStripe);

  // Lexoffice state
  const [lexKey, setLexKey] = useState("");
  const [lexVisible, setLexVisible] = useState(false);
  const [lexLoading, setLexLoading] = useState(false);
  const [lexMessage, setLexMessage] = useState<{
    type: "success" | "error";
    text: string;
  } | null>(null);

  // Stripe state
  const [stripeKey, setStripeKey] = useState("");
  const [stripeKeyVisible, setStripeKeyVisible] = useState(false);
  const [webhookSecret, setWebhookSecret] = useState("");
  const [webhookSecretVisible, setWebhookSecretVisible] = useState(false);
  const [stripeLoading, setStripeLoading] = useState(false);
  const [stripeMessage, setStripeMessage] = useState<{
    type: "success" | "error";
    text: string;
  } | null>(null);

  useEffect(() => {
    fetchStatus();
  }, [fetchStatus]);

  const handleLexofficeConnect = async () => {
    if (!lexKey.trim()) return;
    setLexLoading(true);
    setLexMessage(null);
    try {
      const msg = await connectLexoffice(lexKey);
      setLexMessage({ type: "success", text: msg });
      setLexKey("");
    } catch (err: unknown) {
      const detail = extractError(err);
      setLexMessage({ type: "error", text: detail });
    } finally {
      setLexLoading(false);
    }
  };

  const handleLexofficeDisconnect = async () => {
    setLexLoading(true);
    setLexMessage(null);
    try {
      await disconnectLexoffice();
      setLexMessage({ type: "success", text: "Verbindung getrennt" });
    } catch {
      setLexMessage({ type: "error", text: "Fehler beim Trennen" });
    } finally {
      setLexLoading(false);
    }
  };

  const handleStripeConnect = async () => {
    if (!stripeKey.trim() || !webhookSecret.trim()) return;
    setStripeLoading(true);
    setStripeMessage(null);
    try {
      const msg = await connectStripe(stripeKey, webhookSecret);
      setStripeMessage({ type: "success", text: msg });
      setStripeKey("");
      setWebhookSecret("");
    } catch (err: unknown) {
      const detail = extractError(err);
      setStripeMessage({ type: "error", text: detail });
    } finally {
      setStripeLoading(false);
    }
  };

  const handleStripeDisconnect = async () => {
    setStripeLoading(true);
    setStripeMessage(null);
    try {
      await disconnectStripe();
      setStripeMessage({ type: "success", text: "Verbindung getrennt" });
    } catch {
      setStripeMessage({ type: "error", text: "Fehler beim Trennen" });
    } finally {
      setStripeLoading(false);
    }
  };

  if (!canManageIntegrations) {
    return (
      <div className="space-y-4 max-w-2xl">
        <h2 className="text-2xl font-bold text-gray-900">Einstellungen</h2>
        <div className="bg-white rounded-lg border border-gray-200 p-8 text-center">
          <p className="text-gray-500">Nur Administratoren und Inhaber koennen die Einstellungen verwalten.</p>
        </div>
      </div>
    );
  }

  if (isLoading || !status) {
    return <p className="text-gray-500">Laden...</p>;
  }

  const webhookUrl =
    typeof window !== "undefined"
      ? `${window.location.origin}/api/webhooks/stripe`
      : "https://deine-domain.de/api/webhooks/stripe";

  return (
    <div className="space-y-8 max-w-2xl">
      <h2 className="text-2xl font-bold text-gray-900">Einstellungen</h2>

      {/* Lexoffice section */}
      <section className="bg-white rounded-lg border border-gray-200 p-6">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold text-gray-900">Lexoffice</h3>
          {status.lexoffice_connected && (
            <span className="inline-flex items-center gap-1.5 text-sm text-green-700">
              <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path
                  fillRule="evenodd"
                  d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                  clipRule="evenodd"
                />
              </svg>
              Verbunden
            </span>
          )}
        </div>

        {lexMessage && (
          <div
            className={`mb-4 px-4 py-3 rounded text-sm ${
              lexMessage.type === "success"
                ? "bg-green-50 border border-green-200 text-green-700"
                : "bg-red-50 border border-red-200 text-red-700"
            }`}
          >
            {lexMessage.text}
          </div>
        )}

        {!status.lexoffice_connected ? (
          <div className="space-y-3">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                API-Key
              </label>
              <div className="flex gap-2">
                <div className="relative flex-1">
                  <input
                    type={lexVisible ? "text" : "password"}
                    value={lexKey}
                    onChange={(e) => setLexKey(e.target.value)}
                    placeholder="Lexoffice API-Key eingeben"
                    className="w-full px-3 py-2 border border-gray-300 rounded-md pr-10 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  />
                  <button
                    type="button"
                    onClick={() => setLexVisible(!lexVisible)}
                    className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                  >
                    {lexVisible ? "Verbergen" : "Anzeigen"}
                  </button>
                </div>
              </div>
            </div>
            <button
              onClick={handleLexofficeConnect}
              disabled={lexLoading || !lexKey.trim()}
              className="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {lexLoading
                ? "Teste Verbindung..."
                : "Verbindung testen und speichern"}
            </button>
          </div>
        ) : (
          <div className="flex items-center justify-between">
            <p className="text-sm text-gray-600">
              API-Key ist gespeichert und verschluesselt.
            </p>
            <button
              onClick={handleLexofficeDisconnect}
              disabled={lexLoading}
              className="px-3 py-1.5 text-sm text-red-600 border border-red-200 rounded-md hover:bg-red-50 disabled:opacity-50 transition-colors"
            >
              Trennen
            </button>
          </div>
        )}
      </section>

      {/* Stripe section */}
      <section className="bg-white rounded-lg border border-gray-200 p-6">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold text-gray-900">Stripe</h3>
          {status.stripe_connected && (
            <span className="inline-flex items-center gap-1.5 text-sm text-green-700">
              <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path
                  fillRule="evenodd"
                  d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                  clipRule="evenodd"
                />
              </svg>
              Verbunden
            </span>
          )}
        </div>

        {stripeMessage && (
          <div
            className={`mb-4 px-4 py-3 rounded text-sm ${
              stripeMessage.type === "success"
                ? "bg-green-50 border border-green-200 text-green-700"
                : "bg-red-50 border border-red-200 text-red-700"
            }`}
          >
            {stripeMessage.text}
          </div>
        )}

        {!status.stripe_connected ? (
          <div className="space-y-4">
            {/* Secret Key */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Secret Key
              </label>
              <div className="relative">
                <input
                  type={stripeKeyVisible ? "text" : "password"}
                  value={stripeKey}
                  onChange={(e) => setStripeKey(e.target.value)}
                  placeholder="sk_live_... oder sk_test_..."
                  className="w-full px-3 py-2 border border-gray-300 rounded-md pr-20 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
                <button
                  type="button"
                  onClick={() => setStripeKeyVisible(!stripeKeyVisible)}
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-xs"
                >
                  {stripeKeyVisible ? "Verbergen" : "Anzeigen"}
                </button>
              </div>
            </div>

            {/* Webhook URL (read-only) */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Webhook-URL
              </label>
              <div className="flex items-center bg-gray-50 border border-gray-200 rounded-md px-3 py-2">
                <code className="text-sm text-gray-700 truncate flex-1">
                  {webhookUrl}
                </code>
                <CopyButton text={webhookUrl} />
              </div>
            </div>

            {/* Webhook Secret */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Webhook Secret
              </label>
              <div className="relative">
                <input
                  type={webhookSecretVisible ? "text" : "password"}
                  value={webhookSecret}
                  onChange={(e) => setWebhookSecret(e.target.value)}
                  placeholder="whsec_..."
                  className="w-full px-3 py-2 border border-gray-300 rounded-md pr-20 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
                <button
                  type="button"
                  onClick={() =>
                    setWebhookSecretVisible(!webhookSecretVisible)
                  }
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-xs"
                >
                  {webhookSecretVisible ? "Verbergen" : "Anzeigen"}
                </button>
              </div>
            </div>

            {/* Hint */}
            <div className="bg-blue-50 border border-blue-200 rounded-md p-3">
              <p className="text-sm text-blue-800">
                Erstelle einen Webhook in deinem{" "}
                <a
                  href="https://dashboard.stripe.com/webhooks"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="font-medium underline"
                >
                  Stripe Dashboard
                </a>{" "}
                mit folgenden Events:{" "}
                <code className="text-xs bg-blue-100 px-1 py-0.5 rounded">
                  payment_intent.succeeded
                </code>
                ,{" "}
                <code className="text-xs bg-blue-100 px-1 py-0.5 rounded">
                  payment_intent.payment_failed
                </code>
              </p>
            </div>

            <button
              onClick={handleStripeConnect}
              disabled={
                stripeLoading ||
                !stripeKey.trim() ||
                !webhookSecret.trim()
              }
              className="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {stripeLoading
                ? "Teste Verbindung..."
                : "Verbindung testen und speichern"}
            </button>
          </div>
        ) : (
          <div className="flex items-center justify-between">
            <p className="text-sm text-gray-600">
              Secret Key und Webhook Secret sind gespeichert und
              verschluesselt.
            </p>
            <button
              onClick={handleStripeDisconnect}
              disabled={stripeLoading}
              className="px-3 py-1.5 text-sm text-red-600 border border-red-200 rounded-md hover:bg-red-50 disabled:opacity-50 transition-colors"
            >
              Trennen
            </button>
          </div>
        )}
      </section>
    </div>
  );
}

function extractError(err: unknown): string {
  if (
    typeof err === "object" &&
    err !== null &&
    "response" in err &&
    typeof (err as Record<string, unknown>).response === "object"
  ) {
    const resp = (err as { response: { data?: { detail?: string } } }).response;
    return resp.data?.detail ?? "Ein Fehler ist aufgetreten";
  }
  return "Verbindungsfehler";
}
