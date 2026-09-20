// Syncs the horizontal scroll position of an element onto another element it
// names via data-scroll-sync-with="<id>". Used by wide tables that split their
// <thead> and <tbody> into two independently-scrollable <table>s (the header
// one made `position: sticky` so it can freeze relative to the page, which is
// only possible on an element that is itself not inside an `overflow-x: auto`
// ancestor — see docs/ai-context/known-pitfalls.md "position: sticky と横スク
// ロール用テーブルの分割" for why this split is necessary).
//
// Runs on `livewire:navigated` (fired on the initial page load and on every
// wire:navigate transition) and on the `morphed` Livewire JS hook (fired
// after every Livewire re-render — e.g. the wire:poll/wire:model.live
// filters on candidate-check.blade.php, CHG-0016) so the two containers are
// re-aligned whenever a re-render could have moved them independently.
// Livewire v3+ has no `livewire:updated` DOM CustomEvent (confirmed by
// grepping vendor/livewire/livewire/dist/livewire.js for every dispatched
// `livewire:*` event — only init/navigate variants exist); per-render hooks
// are exposed solely via `Livewire.hook(name, callback)` JS callbacks, and
// `morphed` is the one that fires once a component's DOM has been patched.
// A WeakSet tracks which source elements already have a 'scroll' listener
// bound, since Livewire's morphdom typically preserves same-id elements
// across re-renders and re-running querySelectorAll on every update would
// otherwise stack duplicate listeners on the same element.
const scrollSyncBound = new WeakSet();

function bindScrollSync() {
    document.querySelectorAll('[data-scroll-sync-with]').forEach((source) => {
        const target = document.getElementById(source.dataset.scrollSyncWith);
        if (!target) {
            return;
        }

        // Re-align immediately: a re-render can reset one container's
        // scrollLeft (e.g. its content width changed) without resetting the
        // other, leaving them out of sync until the next user scroll.
        target.scrollLeft = source.scrollLeft;

        if (!scrollSyncBound.has(source)) {
            source.addEventListener('scroll', () => {
                target.scrollLeft = source.scrollLeft;
            });
            scrollSyncBound.add(source);
        }
    });
}

document.addEventListener('livewire:navigated', bindScrollSync);
window.Livewire.hook('morphed', bindScrollSync);
