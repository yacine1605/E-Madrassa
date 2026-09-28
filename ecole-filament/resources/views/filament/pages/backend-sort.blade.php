<div x-show="config.module !== 'dashboard' && resource" style="display:flex;justify-content:flex-end;margin:-10px 0 4px">
    <button class="backend-sort-button" style="border:1px solid #d9e5e6;background:#fff;color:#143b49;border-radius:8px;padding:9px 12px;font-size:13px;font-weight:700;cursor:pointer" @click="toggleSort()" :disabled="!rows.length" :aria-label="sortDirection === 'asc' ? 'Tri alphabétique descendant' : 'Tri alphabétique ascendant'" :title="sortDirection === 'asc' ? 'A à Z' : 'Z à A'"><span x-text="sortDirection === 'asc' ? 'A → Z' : 'Z → A'"></span></button>
</div>
