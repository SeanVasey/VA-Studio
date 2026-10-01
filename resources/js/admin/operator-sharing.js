// This trusted expression is escaped into the sharing view's x-data attribute.
// Public values come from readonly DOM fields, never from executable interpolation.
(function () {
    return {
        copying: null,
        status: '',
        operation: 0,
        disposed: false,

        field(kind) {
            if (kind === 'link') return this.$refs.trackLink;
            if (kind === 'embed') return this.$refs.embedCode;
            return null;
        },

        current(operation, field) {
            return !this.disposed && this.operation === operation
                && this.$root?.isConnected && field.isConnected && this.$root.contains(field);
        },

        manualMessage(kind) {
            return kind === 'link'
                ? 'Clipboard is unavailable. Select the public link field and copy it manually.'
                : 'Clipboard is unavailable. Select the embed code field and copy it manually.';
        },

        async copy(kind) {
            const field = this.field(kind);
            if (this.disposed || this.copying !== null || !field
                || !this.$root?.isConnected || !field.isConnected || !this.$root.contains(field)) return;

            const operation = ++this.operation;
            this.copying = kind;
            this.status = kind === 'link' ? 'Copying link…' : 'Copying embed code…';

            try {
                const clipboard = globalThis.navigator?.clipboard;
                if (!globalThis.isSecureContext || typeof clipboard?.writeText !== 'function') {
                    if (this.current(operation, field)) this.status = this.manualMessage(kind);
                    return;
                }

                const written = clipboard.writeText(field.value);
                if (!written || typeof written.then !== 'function') {
                    throw new Error('Clipboard did not return a completion promise.');
                }
                await written;
                if (this.current(operation, field)) {
                    this.status = kind === 'link' ? 'Public link copied.' : 'Embed code copied.';
                }
            } catch {
                // Permission/provider failures do not expose browser error details.
                if (this.current(operation, field)) this.status = this.manualMessage(kind);
            } finally {
                if (!this.disposed && this.operation === operation) this.copying = null;
            }
        },

        select(kind) {
            const field = this.field(kind);
            if (this.disposed || this.copying !== null || !field
                || !this.$root?.isConnected || !field.isConnected || !this.$root.contains(field)) return;

            try {
                field.focus();
                field.select();
                this.status = kind === 'link'
                    ? 'Public link selected. Use your device’s copy command.'
                    : 'Embed code selected. Use your device’s copy command.';
            } catch {
                this.status = 'Select the field and copy it manually.';
            }
        },

        cancel() {
            // A settled callback from a closed/replaced modal cannot change new UI state.
            ++this.operation;
            this.copying = null;
            this.status = '';
        },

        destroy() {
            this.cancel();
            this.disposed = true;
        },
    };
})()
