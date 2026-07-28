// Status messages fade out after 5 seconds. Alerts that must stay on screen
// carry .alert-persistent.
document.querySelectorAll(".alert:not(.alert-persistent)").forEach((alert) => {
  window.setTimeout(() => {
    alert.classList.add("alert-fading");
    window.setTimeout(() => alert.remove(), 400);
  }, 5000);
});
