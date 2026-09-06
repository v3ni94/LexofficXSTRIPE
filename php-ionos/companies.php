<?php
/**
 * Firmen verwalten: zwischen den eigenen Firmen (Organisationen) wechseln
 * und neue anlegen. Jede Firma hat vollständig getrennte Kunden, Rechnungen,
 * Einzüge und eigene Lexware Office-/Stripe-Anbindung (unter "Einstellungen" der
 * jeweils aktiven Firma zu hinterlegen).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/layout.php';

$ctx = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $result = create_company(
                $ctx['user_id'],
                $_POST['org_name'] ?? '',
                $_POST['mandate_prefix'] ?? ''
            );
            if ($result['error']) {
                throw new RuntimeException($result['error']);
            }
            switch_company($ctx['user_id'], $result['org_id']);
            flash_set('success', 'Die neue Firma wurde Ihrem Benutzerkonto hinzugefügt und aktiviert. Bitte jetzt Lexware Office und Stripe verbinden. Über die Firmenübersicht wechseln Sie zwischen Ihren Firmen.');
            redirect('onboarding.php');

        } elseif ($action === 'switch') {
            if (!switch_company($ctx['user_id'], $_POST['org_id'] ?? '')) {
                throw new RuntimeException('Sie sind kein Mitglied dieser Firma.');
            }
            flash_set('success', 'Firma gewechselt.');
            redirect('dashboard.php');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Fehler: ' . $e->getMessage());
        redirect('companies.php');
    }
}

$companies = list_user_companies($ctx['user_id']);
$ma = user_multiaccount_state($ctx['user_id']);

layout_header('Firmenübersicht', $ctx);
?>
<h1>Firmenübersicht</h1>
<p class="page-sub">Mehrere Firmen mit jeweils vollständig getrennten Kunden, Rechnungen, Einzügen,
    eigener Lexware Office-/Stripe-Anbindung und eigenem Abonnement verwalten. Multiaccount verbindet nur
    Anmeldung und Navigation, nicht die Datenbestände.</p>
<?php if (!$ma['active']): ?>
<div class="flash flash-info">Multiaccount ist in Ihrem Profil derzeit deaktiviert, daher erscheint die Firmenübersicht nicht im Profilmenü.
    Sie können hier trotzdem eine weitere Firma anlegen; Multiaccount wird dann automatisch aktiviert. Manuell aktivieren Sie es unter
    <a href="team.php#multiaccount">Firmendaten, Mein Profil</a>.</div>
<?php endif; ?>

<div class="card">
    <h2>Ihre Firmen</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Firma</th><th>Mandatspräfix</th><th>Rolle</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($companies as $c): ?>
                <tr>
                    <td><?= e($c['name']) ?>
                        <?php if ($c['id'] === $ctx['org_id']): ?>
                            <span class="badge badge-success">Aktiv</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($c['mandate_prefix']) ?></td>
                    <td><?= e(role_label($c['role'])) ?></td>
                    <td>
                        <?php if ($c['id'] !== $ctx['org_id']): ?>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="switch">
                            <input type="hidden" name="org_id" value="<?= e($c['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Wechseln</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Neue Firma anlegen</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label for="org_name">Firmenname</label>
        <input type="text" id="org_name" name="org_name" required placeholder="z.B. Timo Müller">
        <label for="mandate_prefix">Mandatspräfix (2-10 Zeichen, z.B. "TM")</label>
        <input type="text" id="mandate_prefix" name="mandate_prefix" required maxlength="10"
               pattern="[A-Za-z0-9]{2,10}" style="text-transform: uppercase;">
        <p class="hint">Wird als Anfang der SEPA-Mandatsreferenz der Kunden dieser Firma verwendet
            (z.B. "TM10045"), unabhängig von Ihren anderen Firmen. Nach der Einrichtung nicht mehr
            änderbar.</p>
        <div class="form-actions">
            <button type="submit" class="btn">Firma anlegen</button>
        </div>
        <p class="hint">Sie werden automatisch Inhaber der neuen Firma und richten anschließend
            eigene Lexware Office- und Stripe-Zugänge unter "Einstellungen" ein.</p>
    </form>
</div>
<?php layout_footer($ctx); ?>
