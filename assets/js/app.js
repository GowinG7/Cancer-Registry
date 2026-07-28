/**
 * Shared behaviour: password visibility, diagnosis toggles, ICD site names,
 * cascading province/district selects and delete confirmations.
 */

document.querySelectorAll(".password-toggle").forEach((button) => {
  button.addEventListener("click", () => {
    const input = document.getElementById(button.dataset.target);
    if (!input) return;
    const show = input.type === "password";
    input.type = show ? "text" : "password";
    button.textContent = show ? "Hide" : "Show";
    button.setAttribute("aria-label", show ? "Hide password" : "Show password");
  });
});

document.querySelectorAll(".js-icd-select").forEach((select) => {
  const target = document.getElementById(select.dataset.siteTarget);
  const update = () => {
    const option = select.options[select.selectedIndex];
    if (target) target.value = option ? option.dataset.site || "" : "";
  };
  select.addEventListener("change", update);
  update();
});

document.querySelectorAll(".js-diagnosis-toggle").forEach((checkbox) => {
  const fieldset = document.getElementById(checkbox.dataset.target);
  if (!fieldset) return;
  const select = fieldset.querySelector(".js-icd-select");
  const update = () => {
    fieldset.disabled = !checkbox.checked;
    if (select) select.required = checkbox.checked;
  };
  checkbox.addEventListener("change", update);
  update();
});

function setupProvinceDistrict() {
  const province = document.getElementById("province");
  const district = document.getElementById("district");
  if (!province || !district || typeof nepalProvinces === "undefined") return;

  // The district saved on the record, so editing keeps the current value.
  let preselected = province.dataset.selectedDistrict || "";
  const legacyDistrict = province.dataset.legacyDistrict || "";

  const update = () => {
    const districts = nepalProvinces[province.value] || [];
    if (!districts.length && legacyDistrict && province.value) {
      // Province typed in before the dropdowns existed: keep its district.
      district.innerHTML = "";
      const option = document.createElement("option");
      option.value = legacyDistrict;
      option.textContent = legacyDistrict + " (currently saved)";
      option.selected = true;
      district.appendChild(option);
      district.disabled = false;
      return;
    }
    district.innerHTML = "";
    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = districts.length
      ? "-- Select District --"
      : "-- Select Province First --";
    district.appendChild(placeholder);
    districts.forEach((name) => {
      const option = document.createElement("option");
      option.value = name;
      option.textContent = name;
      option.selected = name === preselected;
      district.appendChild(option);
    });
    district.disabled = districts.length === 0;
    preselected = district.value;
  };

  province.addEventListener("change", () => {
    preselected = "";
    update();
  });
  update();
}

setupProvinceDistrict();

document.querySelectorAll(".js-confirm-delete").forEach((form) => {
  form.addEventListener("submit", (event) => {
    const name = form.dataset.name || "this record";
    const message =
      `Delete "${name}"?\n\n` +
      "The patient record and its diagnoses will be permanently removed.";
    if (!window.confirm(message)) event.preventDefault();
  });
});

// Status messages fade out after 5 seconds. Alerts that must stay on screen
// (form errors, the pending-upgrade notice) carry .alert-persistent.
document.querySelectorAll(".alert:not(.alert-persistent)").forEach((alert) => {
  window.setTimeout(() => {
    alert.classList.add("alert-fading");
    window.setTimeout(() => alert.remove(), 400);
  }, 5000);
});
