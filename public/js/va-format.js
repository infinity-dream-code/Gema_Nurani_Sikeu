/**
 * Format No. VA: prefix + NIS (total 16 digit).
 * Contoh: 752000 + 123 => 7520000000000123
 */
(function (window) {
    const DEFAULT_PREFIX = '752000';
    const TOTAL_LENGTH = 16;

    window.formatNoVA = function (nis, prefix) {
        const vaPrefix = String(prefix ?? window.APP_VA_PREFIX ?? DEFAULT_PREFIX).replace(/\D/g, '') || DEFAULT_PREFIX;
        const digits = String(nis ?? '').replace(/\D/g, '');
        if (!digits) {
            return '';
        }
        const padLen = Math.max(1, TOTAL_LENGTH - vaPrefix.length);
        return vaPrefix + digits.padStart(padLen, '0');
    };
})(window);
