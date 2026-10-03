document.addEventListener('submit', function (event) {
  const form = event.target;
  if (form instanceof HTMLFormElement && form.dataset.payrollConfirm && !window.confirm(form.dataset.payrollConfirm)) event.preventDefault();
});
