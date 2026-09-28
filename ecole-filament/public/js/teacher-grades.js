document.querySelectorAll('[data-grade-row]').forEach(row => {
    const status = row.querySelector('[data-grade-status]');
    const score = row.querySelector('[data-grade-score]');
    const update = () => { score.disabled = status.value !== 'marked'; };
    status.addEventListener('change', update);
    update();
});
