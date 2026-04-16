<?php
// ================================================================
//  SmartRecruit — Envoi email via SMTP (sans Composer, sans Psr)
//  Fichier : config/PHPMailer/mailer.php
//
//  Ce fichier est un wrapper qui :
//  1. Charge les 3 fichiers PHPMailer téléchargés manuellement
//  2. Lit la config SMTP depuis .env via getenv()
//  3. Expose une seule fonction : envoyerEmailSmtp()
//
//  L'erreur \Psr\Log\LoggerInterface dans PHPMailer.php et SMTP.php
//  est inoffensive quand SMTPDebug = 0 — le bloc if() n'est jamais
//  exécuté, donc l'absence de la classe Psr ne cause aucune erreur.
// ================================================================

require_once __DIR__ . '/Exception.php';
require_once __DIR__ . '/PHPMailer.php';
require_once __DIR__ . '/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Envoie un email via SMTP Gmail.
 *
 * @param string $dest_email  Email du destinataire
 * @param string $dest_nom    Nom du destinataire
 * @param string $sujet       Sujet de l'email
 * @param string $corps       Corps du message (texte brut)
 * @param array  $expediteur  ['email'=>..., 'nom'=>...] de l'admin connecté
 *
 * @return array ['succes'=>bool, 'message'=>string]
 */
function envoyerEmailSmtp(
    string $dest_email,
    string $dest_nom,
    string $sujet,
    string $corps,
    array  $expediteur = []
): array {

    $mail_from = getenv('MAIL_FROM')     ?: '';
    $mail_pass = getenv('MAIL_PASSWORD') ?: '';
    $mail_nom  = getenv('MAIL_NOM')      ?: 'SmartRecruit RH';
    $mail_host = getenv('MAIL_HOST')     ?: 'smtp.gmail.com';
    $mail_port = (int)(getenv('MAIL_PORT') ?: 587);

    if (empty($mail_from) || empty($mail_pass)) {
        return [
            'succes'  => false,
            'message' => 'Configuration email incomplète. Vérifiez le fichier .env (MAIL_FROM et MAIL_PASSWORD).',
        ];
    }

    $mail = new PHPMailer(true);

    try {
        // Configuration SMTP
        $mail->isSMTP();
        $mail->SMTPDebug  = 0;   // 0=silencieux | 2=verbose pour déboguer
        $mail->Host       = $mail_host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $mail_from;
        $mail->Password   = $mail_pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $mail_port;
        $mail->CharSet    = 'UTF-8';

        // Expéditeur
        $mail->setFrom($mail_from, $mail_nom);

        // Reply-To = l'admin connecté (le candidat lui répond directement)
        if (!empty($expediteur['email'])) {
            $mail->addReplyTo($expediteur['email'], $expediteur['nom'] ?? $mail_nom);
        }

        // Destinataire
        $mail->addAddress($dest_email, $dest_nom);

        // Contenu texte brut
        $mail->isHTML(false);
        $mail->Subject = $sujet;
        $mail->Body    = $corps;

        $mail->send();

        return [
            'succes'  => true,
            'message' => "Email envoyé avec succès à $dest_nom ($dest_email).",
        ];

    } catch (MailException $e) {
        return [
            'succes'  => false,
            'message' => "Échec de l'envoi : " . $mail->ErrorInfo,
        ];
    }
}
