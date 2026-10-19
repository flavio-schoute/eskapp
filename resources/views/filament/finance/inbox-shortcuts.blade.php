{{--
    Keyboard shortcuts for the inbox, like the original Finance app: j/k (or arrows) move between lines, Enter opens
    the category, a accepts the proposal or suggestion, r makes a rule, x excludes, space selects. Typing in a field
    never triggers a shortcut.
--}}
<style>
    .fi-ta-row.finance-row-focus > td { background: rgb(217 119 6 / 0.08) !important; }
    .fi-ta-row.finance-row-focus > td:first-child { box-shadow: inset 3px 0 0 #D97706; }
    kbd { font-family: ui-monospace, monospace; font-size: 0.75rem; padding: 0 0.3rem; border-radius: 0.25rem; box-shadow: inset 0 0 0 1px rgb(127 127 127 / 0.35); }
</style>
<script>
    (() => {
        if (window.financeInboxShortcuts) {
            return;
        }

        window.financeInboxShortcuts = true;

        let index = -1;

        const rows = () => [...document.querySelectorAll('.fi-ta-table tbody tr.fi-ta-row')];

        const focus = (next) => {
            const all = rows();

            all.forEach((row) => row.classList.remove('finance-row-focus'));

            if (all.length === 0) {
                index = -1;

                return null;
            }

            index = Math.max(0, Math.min(next, all.length - 1));
            all[index].classList.add('finance-row-focus');
            all[index].scrollIntoView({ block: 'nearest' });

            return all[index];
        };

        const current = () => (index >= 0 ? rows()[index] ?? null : null);

        document.addEventListener('keydown', (event) => {
            if (! document.querySelector('[data-finance-inbox]')) {
                return;
            }

            const target = event.target;
            const typing = target.closest('input, textarea, select, [contenteditable], .fi-modal-window, [role="listbox"], .fi-dropdown-panel');

            if (typing || event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            const row = current();
            const click = (selector) => row?.querySelector(selector)?.click();

            switch (event.key) {
                case 'j':
                case 'ArrowDown':
                    focus(index + 1);
                    break;
                case 'k':
                case 'ArrowUp':
                    focus(index - 1);
                    break;
                case 'Enter':
                    if (! row) return;
                    (row.querySelector('.fi-ta-select-column button, .fi-ta-select-column select, .fi-select-input-btn') ?? row.querySelector('.fi-ta-select-column'))?.click();
                    row.querySelector('.fi-ta-select-column select')?.focus();
                    break;
                case 'a':
                case 's':
                    click('[data-shortcut="accept"]');
                    break;
                case 'r':
                    click('[data-shortcut="rule"]');
                    break;
                case 'x':
                    click('[data-shortcut="exclude"]');
                    break;
                case ' ':
                    if (! row) return;
                    click('input[type="checkbox"]');
                    break;
                case 'Escape':
                    rows().forEach((r) => r.classList.remove('finance-row-focus'));
                    index = -1;
                    return;
                default:
                    return;
            }

            event.preventDefault();
        });

        // Keep the focused line highlighted after Livewire re-renders the table (e.g. when a line left the inbox).
        document.addEventListener('livewire:init', () => {
            Livewire.hook('morphed', () => {
                if (index >= 0) {
                    requestAnimationFrame(() => focus(index));
                }
            });
        });
    })();
</script>
<span data-finance-inbox hidden></span>
