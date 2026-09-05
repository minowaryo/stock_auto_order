// Syncs the horizontal scroll position of an element onto another element it
// names via data-scroll-sync-with="<id>". Used by wide tables that split their
// <thead> and <tbody> into two independently-scrollable <table>s (the header
// one made `position: sticky` so it can freeze relative to the page, which is
// only possible on an element that is itself not inside an `overflow-x: auto`
// ancestor — see docs/ai-context/known-pitfalls.md "position: sticky と横スク
// ロール用テーブルの分割" for why this split is necessary).
// Runs on `livewire:navigated`, which Livewire fires on the initial page load
// and on every subsequent wire:navigate transition, so listeners are (re)bound
// whichever way the page was reached.
document.addEventListener('livewire:navigated', () => {
    document.querySelectorAll('[data-scroll-sync-with]').forEach((source) => {
        const target = document.getElementById(source.dataset.scrollSyncWith);
        if (!target) {
            return;
        }

        source.addEventListener('scroll', () => {
            target.scrollLeft = source.scrollLeft;
        });
    });
});
