(() => {
  'use strict';
  const form = document.querySelector('[data-hd-filters]');
  if (!form) return;
  const boxes = [...form.querySelectorAll('input[name="periods[]"]')];
  const count = form.querySelector('.hd-period-picker legend span');
  const update = () => { count.textContent = `(${boxes.filter(box => box.checked).length} selected)`; };
  form.querySelectorAll('[data-hd-select]').forEach(button => {
    button.hidden = false;
    button.addEventListener('click', () => {
      boxes.forEach(box => { box.checked = button.dataset.hdSelect === 'all'; });
      update();
    });
  });
  boxes.forEach(box => box.addEventListener('change', update));
  form.querySelector('[data-hd-mode]').addEventListener('change', () => form.requestSubmit());
})();
