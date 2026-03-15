import { useEffect, useState } from "react";
import { api } from "../api/client";
import { usePermission } from "../hooks/usePermission";

interface Member {
  user_id: string;
  email: string;
  display_name: string | null;
  role: string;
  joined_at: string | null;
}

interface InvitationItem {
  id: string;
  email: string;
  role: string;
  status: string;
  expires_at: string;
  created_at: string;
}

export default function TeamPage() {
  const { canManageTeam, canChangeRoles } = usePermission();
  const [members, setMembers] = useState<Member[]>([]);
  const [invitations, setInvitations] = useState<InvitationItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Invite modal state
  const [showInviteModal, setShowInviteModal] = useState(false);
  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteRole, setInviteRole] = useState("member");
  const [inviteResult, setInviteResult] = useState<{
    invite_url: string;
  } | null>(null);
  const [inviting, setInviting] = useState(false);

  const fetchData = async () => {
    setLoading(true);
    try {
      const [membersRes, invitationsRes] = await Promise.all([
        api.get("/organization/members"),
        canManageTeam
          ? api.get("/organization/invitations")
          : Promise.resolve({ data: [] }),
      ]);
      setMembers(membersRes.data);
      setInvitations(invitationsRes.data);
    } catch {
      setError("Fehler beim Laden der Team-Daten");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, []);

  const handleInvite = async () => {
    setInviting(true);
    setError(null);
    try {
      const res = await api.post("/organization/invite", {
        email: inviteEmail,
        role: inviteRole,
      });
      setInviteResult({ invite_url: res.data.invite_url });
      fetchData();
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
      setInviting(false);
    }
  };

  const revokeInvitation = async (id: string) => {
    try {
      await api.delete(`/organization/invitations/${id}`);
      fetchData();
    } catch {
      setError("Fehler beim Widerrufen der Einladung");
    }
  };

  const removeMember = async (userId: string) => {
    if (!confirm("Mitglied wirklich entfernen?")) return;
    try {
      await api.delete(`/organization/members/${userId}`);
      fetchData();
    } catch (err: unknown) {
      const detail =
        typeof err === "object" &&
        err !== null &&
        "response" in err
          ? ((err as { response: { data?: { detail?: string } } }).response
              .data?.detail ?? "Fehler")
          : "Verbindungsfehler";
      setError(detail);
    }
  };

  const changeRole = async (userId: string, newRole: string) => {
    try {
      await api.put(`/organization/members/${userId}/role`, { role: newRole });
      fetchData();
    } catch (err: unknown) {
      const detail =
        typeof err === "object" &&
        err !== null &&
        "response" in err
          ? ((err as { response: { data?: { detail?: string } } }).response
              .data?.detail ?? "Fehler")
          : "Verbindungsfehler";
      setError(detail);
    }
  };

  const roleLabels: Record<string, string> = {
    owner: "Inhaber",
    admin: "Admin",
    member: "Mitglied",
  };

  const roleBadge = (role: string) => {
    const styles: Record<string, string> = {
      owner: "bg-purple-100 text-purple-800",
      admin: "bg-blue-100 text-blue-800",
      member: "bg-gray-100 text-gray-700",
    };
    return (
      <span
        className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${
          styles[role] ?? "bg-gray-100 text-gray-600"
        }`}
      >
        {roleLabels[role] ?? role}
      </span>
    );
  };

  if (!canManageTeam) {
    return (
      <div className="space-y-4">
        <h2 className="text-2xl font-bold text-gray-900">Team</h2>
        <p className="text-gray-500">
          Nur Administratoren koennen die Teamverwaltung einsehen.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold text-gray-900">Team</h2>
        <button
          onClick={() => {
            setShowInviteModal(true);
            setInviteResult(null);
            setInviteEmail("");
            setInviteRole("member");
          }}
          className="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700"
        >
          Nutzer einladen
        </button>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded text-sm">
          {error}
        </div>
      )}

      {loading ? (
        <p className="text-gray-500">Laden...</p>
      ) : (
        <>
          {/* Members */}
          <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <div className="px-4 py-3 border-b border-gray-200 bg-gray-50">
              <h3 className="text-sm font-medium text-gray-700">
                Mitglieder ({members.length})
              </h3>
            </div>
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Name
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    E-Mail
                  </th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Rolle
                  </th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                    Aktionen
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {members.map((m) => (
                  <tr key={m.user_id}>
                    <td className="px-4 py-3 text-sm text-gray-900">
                      {m.display_name || "-"}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">
                      {m.email}
                    </td>
                    <td className="px-4 py-3">{roleBadge(m.role)}</td>
                    <td className="px-4 py-3 text-right space-x-2">
                      {canChangeRoles && m.role !== "owner" && (
                        <select
                          value={m.role}
                          onChange={(e) => changeRole(m.user_id, e.target.value)}
                          className="text-xs px-2 py-1 border border-gray-300 rounded"
                        >
                          <option value="admin">Admin</option>
                          <option value="member">Mitglied</option>
                        </select>
                      )}
                      {m.role !== "owner" && (
                        <button
                          onClick={() => removeMember(m.user_id)}
                          className="text-xs text-red-600 hover:text-red-800"
                        >
                          Entfernen
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Invitations */}
          {invitations.filter((i) => i.status === "pending").length > 0 && (
            <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
              <div className="px-4 py-3 border-b border-gray-200 bg-gray-50">
                <h3 className="text-sm font-medium text-gray-700">
                  Offene Einladungen
                </h3>
              </div>
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                      E-Mail
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                      Rolle
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                      Laeuft ab
                    </th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                      Aktion
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {invitations
                    .filter((i) => i.status === "pending")
                    .map((inv) => (
                      <tr key={inv.id}>
                        <td className="px-4 py-3 text-sm">{inv.email}</td>
                        <td className="px-4 py-3">{roleBadge(inv.role)}</td>
                        <td className="px-4 py-3 text-sm text-gray-500">
                          {new Date(inv.expires_at).toLocaleDateString("de-DE")}
                        </td>
                        <td className="px-4 py-3 text-right">
                          <button
                            onClick={() => revokeInvitation(inv.id)}
                            className="text-xs text-red-600 hover:text-red-800"
                          >
                            Widerrufen
                          </button>
                        </td>
                      </tr>
                    ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}

      {/* Invite Modal */}
      {showInviteModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">
              Nutzer einladen
            </h3>

            {inviteResult ? (
              <div className="space-y-4">
                <p className="text-sm text-green-700 bg-green-50 p-3 rounded">
                  Einladung erstellt! Teile diesen Link:
                </p>
                <div className="bg-gray-50 p-3 rounded font-mono text-xs break-all">
                  {window.location.origin}{inviteResult.invite_url}
                </div>
                <button
                  onClick={() => {
                    navigator.clipboard.writeText(
                      `${window.location.origin}${inviteResult.invite_url}`
                    );
                  }}
                  className="w-full px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 text-sm"
                >
                  Link kopieren
                </button>
                <button
                  onClick={() => setShowInviteModal(false)}
                  className="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm"
                >
                  Schliessen
                </button>
              </div>
            ) : (
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    E-Mail
                  </label>
                  <input
                    type="email"
                    value={inviteEmail}
                    onChange={(e) => setInviteEmail(e.target.value)}
                    placeholder="nutzer@example.com"
                    className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Rolle
                  </label>
                  <select
                    value={inviteRole}
                    onChange={(e) => setInviteRole(e.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="member">Mitglied</option>
                    <option value="admin">Admin</option>
                  </select>
                </div>
                <div className="flex gap-3 justify-end pt-2">
                  <button
                    onClick={() => setShowInviteModal(false)}
                    className="px-4 py-2 text-sm border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
                  >
                    Abbrechen
                  </button>
                  <button
                    onClick={handleInvite}
                    disabled={inviting || !inviteEmail.trim()}
                    className="px-4 py-2 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                  >
                    {inviting ? "Wird erstellt..." : "Einladung senden"}
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
