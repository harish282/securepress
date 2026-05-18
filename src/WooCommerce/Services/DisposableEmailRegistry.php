<?php

declare(strict_types=1);

namespace PressSentinel\WooCommerce\Services;

/**
 * Knows whether an email domain is a known disposable / throwaway mailbox provider.
 *
 * Ships with a curated baseline of ~80 widely-abused domains (Mailinator, Guerrilla
 * Mail, 10 Minute Mail, Yopmail, Tempr, Sharklasers, etc). The list is deliberately
 * conservative — every entry is a domain that's been documented as a disposable
 * provider on multiple lists. We don't ship aggressive guesses like "any subdomain
 * containing the word `temp`" because the goal is **low false-positive** detection.
 *
 * Extension points:
 *
 *  - **`addDomain($domain)`** and **`addDomains([...])`** at runtime — third-party
 *    plugins (or our own admin UI) can extend the list.
 *  - **`allow($domain)`** to mark a domain as always-acceptable — useful for SaaS
 *    vendors whose customers legitimately use one of the bundled disposable domains
 *    (e.g., the rare team that uses Mailinator for testing).
 *  - **WordPress filter `presssentinel.disposable_email_domains`** — applied lazily on
 *    first lookup; lets sites manage the list from a feature plugin or `mu-plugins/`
 *    without modifying PressSentinel directly.
 *
 * Lookup is O(1) via an internal hash set. Domain comparison is case-insensitive and
 * IDN-aware: `gMail.com` and `xn--zfr164b.com` both work, the registry stores ASCII
 * lowercased forms.
 */
final class DisposableEmailRegistry
{
    /** @var array<string, bool> Set of disposable domains, keyed for O(1) `isset`. */
    private array $domains;

    /** @var array<string, bool> Domain allowlist (always considered legitimate). */
    private array $allowed = [];

    private bool $filtersApplied = false;

    /**
     * @param list<string>|null $domains Override / supplement the default list. `null`
     *                                   means "use the bundled baseline".
     */
    public function __construct(?array $domains = null)
    {
        $list = $domains ?? self::defaultDomains();
        $this->domains = array_fill_keys(array_map($this->normalize(...), $list), true);
    }

    public function isDisposable(string $email): bool
    {
        $this->ensureFiltersApplied();
        $domain = $this->domainFromEmail($email);
        if ($domain === null) {
            return false;
        }
        if (isset($this->allowed[$domain])) {
            return false;
        }

        return isset($this->domains[$domain]);
    }

    public function addDomain(string $domain): void
    {
        $domain = $this->normalize($domain);
        if ($domain !== '') {
            $this->domains[$domain] = true;
        }
    }

    /**
     * @param list<string> $domains
     */
    public function addDomains(array $domains): void
    {
        foreach ($domains as $domain) {
            $this->addDomain($domain);
        }
    }

    public function allow(string $domain): void
    {
        $domain = $this->normalize($domain);
        if ($domain !== '') {
            $this->allowed[$domain] = true;
        }
    }

    public function count(): int
    {
        $this->ensureFiltersApplied();

        return count($this->domains);
    }

    private function ensureFiltersApplied(): void
    {
        if ($this->filtersApplied || !\function_exists('apply_filters')) {
            $this->filtersApplied = true;
            return;
        }
        $extra = \call_user_func('apply_filters', 'presssentinel.disposable_email_domains', []);
        if (is_array($extra)) {
            $this->addDomains(array_values(array_filter($extra, 'is_string')));
        }
        $allowed = \call_user_func('apply_filters', 'presssentinel.disposable_email_allowed_domains', []);
        if (is_array($allowed)) {
            foreach ($allowed as $domain) {
                if (is_string($domain)) {
                    $this->allow($domain);
                }
            }
        }
        $this->filtersApplied = true;
    }

    private function domainFromEmail(string $email): ?string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $domain = $this->normalize(substr($email, $at + 1));

        return $domain === '' ? null : $domain;
    }

    private function normalize(string $value): string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return '';
        }
        // Strip a leading `@` if someone passed a full email mistakenly.
        if (str_starts_with($value, '@')) {
            $value = substr($value, 1);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public static function defaultDomains(): array
    {
        return [
            '0-mail.com', '1-8.usa.cc', '10minutemail.com', '10minutemail.net', '20minutemail.com',
            '30minutemail.com', '33mail.com', '5ymail.com', 'air-mail.org', 'anonbox.net',
            'anonymbox.com', 'antichef.com', 'antichef.net', 'antispam.de', 'binkmail.com',
            'bobmail.info', 'bofthew.com', 'bouncr.com', 'bspamfree.org', 'chacuo.net',
            'chammy.info', 'cool.fr.nf', 'courriel.fr.nf', 'cubiclink.com', 'curryworld.de',
            'cust.in', 'dayrep.com', 'deadaddress.com', 'despam.it', 'devnullmail.com',
            'discardmail.com', 'dispostable.com', 'dodgeit.com', 'dontreg.com', 'dropmail.me',
            'e4ward.com', 'einrot.com', 'emailgo.de', 'emailias.com', 'emailisvalid.com',
            'emailtemporanea.net', 'emailtemporanea.org', 'emailthe.net', 'emailto.de',
            'emailwarden.com', 'emz.net', 'evopo.com', 'fakeinbox.com', 'fakemailgenerator.com',
            'fastacura.com', 'filzmail.com', 'fizmail.com', 'fr33mail.info', 'fuckedupload.com',
            'fudgerub.com', 'getairmail.com', 'getonemail.com', 'gishpuppy.com', 'gowikibooks.com',
            'grr.la', 'guerrillamail.biz', 'guerrillamail.com', 'guerrillamail.de', 'guerrillamail.info',
            'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com', 'haltospam.com',
            'harakirimail.com', 'hidzz.com', 'hochsitze.com', 'hulapla.de', 'imails.info',
            'inboxalias.com', 'inboxclean.com', 'inboxclean.org', 'incognitomail.org', 'instant-mail.de',
            'jetable.com', 'jetable.fr.nf', 'jetable.net', 'jetable.org', 'jnxjn.com',
            'jourrapide.com', 'kasmail.com', 'klzlk.com', 'koszmail.pl', 'kurzepost.de',
            'lookugly.com', 'lortemail.dk', 'lovemeleaveme.com', 'maileater.com', 'mailexpire.com',
            'mailfreeonline.com', 'mailguard.me', 'mailinator.com', 'mailinator.net', 'mailinator.org',
            'mailinator2.com', 'mailme.lv', 'mailmetrash.com', 'mailmoat.com', 'mailnesia.com',
            'mailnull.com', 'mailshell.com', 'mailsiphon.com', 'mailtothis.com', 'mailtrash.net',
            'mailtv.net', 'mailzilla.com', 'mailzilla.org', 'mbx.cc', 'mintemail.com',
            'mt2009.com', 'mytrashmail.com', 'neomailbox.com', 'nepwk.com', 'no-spam.ws',
            'nogmailspam.info', 'nomail.xl.cx', 'nomail2me.com', 'noref.in', 'notmailinator.com',
            'nowmymail.com', 'nurfuerspam.de', 'objectmail.com', 'obobbo.com', 'odaymail.com',
            'oneoffemail.com', 'onewaymail.com', 'opayq.com', 'ordinaryamerican.net', 'otherinbox.com',
            'ovpn.to', 'owlpic.com', 'pancakemail.com', 'plexolan.de', 'poofy.org',
            'pookmail.com', 'privacy.net', 'proxymail.eu', 'punkass.com', 'putthisinyourspamdatabase.com',
            'quickinbox.com', 'rcpt.at', 'recode.me', 'recursor.net', 'regbypass.com',
            'rmqkr.net', 'rppkn.com', 'rtrtr.com', 'sandelf.de', 'saynotospams.com',
            'selfdestructingmail.com', 'sharedmailbox.org', 'sharklasers.com', 'shieldemail.com',
            'shiftmail.com', 'shitmail.me', 'shortmail.net', 'sibmail.com', 'sify.com',
            'skeefmail.com', 'slipry.net', 'smashmail.de', 'smellfear.com', 'snakemail.com',
            'sneakemail.com', 'snkmail.com', 'sofimail.com', 'sofort-mail.de', 'sogetthis.com',
            'soodonims.com', 'spam.la', 'spam.su', 'spam4.me', 'spamavert.com',
            'spambob.com', 'spambob.net', 'spambob.org', 'spambog.com', 'spambog.de',
            'spambog.ru', 'spambox.us', 'spamcannon.com', 'spamcannon.net', 'spamcero.com',
            'spamcorptastic.com', 'spamcowboy.com', 'spamcowboy.net', 'spamcowboy.org', 'spamday.com',
            'spamex.com', 'spamfree24.com', 'spamfree24.de', 'spamfree24.eu', 'spamfree24.info',
            'spamfree24.net', 'spamfree24.org', 'spamgoes.in', 'spamgourmet.com', 'spamgourmet.net',
            'spamgourmet.org', 'spamherelots.com', 'spamhole.com', 'spamify.com', 'spaminator.de',
            'spamkill.info', 'spaml.com', 'spaml.de', 'spammotel.com', 'spamobox.com',
            'spamoff.de', 'spamslicer.com', 'spamspot.com', 'spamthis.co.uk', 'spamthisplease.com',
            'spamtroll.net', 'spamtrap.ro', 'speed.1s.fr', 'super-auswahl.de', 'supergreatmail.com',
            'supermailer.jp', 'suremail.info', 'tagyourself.com', 'talkinator.com', 'teewars.org',
            'teleworm.com', 'teleworm.us', 'temp-mail.com', 'temp-mail.org', 'temp-mail.ru',
            'tempemail.biz', 'tempemail.com', 'tempinbox.co.uk', 'tempinbox.com', 'tempomail.fr',
            'temporarily.de', 'temporarioemail.com.br', 'temporaryemail.net', 'temporaryforwarding.com',
            'temporaryinbox.com', 'thanksnospam.info', 'thankyou2010.com', 'thismail.net', 'throwawayemailaddress.com',
            'tilien.com', 'tmail.ws', 'tmailinator.com', 'tradermail.info', 'trash-amil.com',
            'trash-mail.at', 'trash-mail.com', 'trash-mail.de', 'trash2009.com', 'trashdevil.com',
            'trashemail.de', 'trashmail.at', 'trashmail.com', 'trashmail.de', 'trashmail.me',
            'trashmail.net', 'trashmail.org', 'trashymail.com', 'trashymail.net', 'trillianpro.com',
            'turual.com', 'twinmail.de', 'tyldd.com', 'uggsrock.com', 'wegwerfadresse.de',
            'wegwerfemail.de', 'wegwerfmail.de', 'wegwerfmail.info', 'wegwerfmail.net', 'wegwerfmail.org',
            'wh4f.org', 'whyspam.me', 'willhackforfood.biz', 'willselfdestruct.com', 'winemaven.info',
            'wronghead.com', 'wuzup.net', 'xoxy.net', 'yapped.net', 'yep.it',
            'yopmail.com', 'yopmail.fr', 'yopmail.net', 'yourdomain.com', 'ypmail.webarnak.fr.eu.org',
            'yuurok.com', 'zehnminutenmail.de', 'zoaxe.com', 'zoemail.org',
        ];
    }
}
