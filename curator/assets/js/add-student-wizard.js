(function () {
    'use strict';

    const STEPS = [
        { id: 'wizard-step-main', nav: 'nav-main', badge: 'main-badge', label: 'Личные данные', optional: false },
        { id: 'wizard-step-enrollment', nav: 'nav-enrollment', badge: 'enrollment-badge', label: 'Зачисление', optional: false },
        { id: 'wizard-step-address', nav: 'nav-address', badge: 'address-badge', label: 'Адрес', optional: false },
        { id: 'wizard-step-parents', nav: 'nav-parents', badge: 'parents-badge', label: 'Семья', optional: true },
        { id: 'wizard-step-social', nav: 'nav-social', badge: null, label: 'Социальное', optional: true },
        { id: 'wizard-step-extra', nav: 'nav-extra', badge: null, label: 'Дополнительно', optional: true }
    ];

    let currentStep = 0;

    function getStepEl(index) {
        return document.getElementById(STEPS[index].id);
    }

    function validateCurrentStep() {
        const step = getStepEl(currentStep);
        if (!step) return true;

        const required = step.querySelectorAll('[required]');
        let firstInvalid = null;

        required.forEach(function (field) {
            field.classList.remove('is-invalid');

            if (field.type === 'radio') {
                const group = step.querySelectorAll('input[type="radio"][name="' + field.name + '"]');
                const anyChecked = Array.prototype.some.call(group, function (r) { return r.checked; });
                if (!anyChecked && !firstInvalid) firstInvalid = group[0];
                return;
            }

            if (field.type === 'checkbox') return;

            if (!(field.value || '').trim()) {
                field.classList.add('is-invalid');
                if (!firstInvalid) firstInvalid = field;
            }
        });

        if (firstInvalid) {
            firstInvalid.focus();
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        return true;
    }

    function updateUI() {
        STEPS.forEach(function (step, i) {
            const el = document.getElementById(step.id);
            const nav = document.getElementById(step.nav);
            if (el) el.classList.toggle('active', i === currentStep);
            if (nav) {
                nav.classList.toggle('active', i === currentStep);
                nav.classList.toggle('done', i < currentStep);
            }
        });

        const progress = document.getElementById('wizardTopProgressBar');
        const progressText = document.getElementById('wizardProgressText');
        const introMeta = document.getElementById('wizardIntroMeta');
        const pct = ((currentStep + 1) / STEPS.length) * 100;
        const label = 'Шаг ' + (currentStep + 1) + ' из ' + STEPS.length + ' — ' + STEPS[currentStep].label;

        if (progress) progress.style.width = pct + '%';
        if (progressText) progressText.textContent = label;
        if (introMeta) introMeta.textContent = 'Шаг ' + (currentStep + 1) + ' из ' + STEPS.length;

        const prevBtn = document.getElementById('wizardPrev');
        const nextBtn = document.getElementById('wizardNext');
        const submitBtn = document.getElementById('wizardSubmit');

        if (prevBtn) prevBtn.disabled = currentStep === 0;
        if (nextBtn) {
            nextBtn.classList.toggle('d-none', currentStep === STEPS.length - 1);
            if (STEPS[currentStep].optional && currentStep < STEPS.length - 1) {
                nextBtn.innerHTML = 'Продолжить<i class="bi bi-arrow-right ms-1"></i>';
            } else if (currentStep < STEPS.length - 1) {
                nextBtn.innerHTML = 'Далее<i class="bi bi-arrow-right ms-1"></i>';
            }
        }
        if (submitBtn) submitBtn.classList.toggle('d-none', currentStep !== STEPS.length - 1);
    }

    function goToStep(index) {
        if (index < 0 || index >= STEPS.length) return;
        currentStep = index;
        updateUI();

        const form = document.getElementById('add-student-form');
        if (form) {
            const top = form.getBoundingClientRect().top + window.pageYOffset - 80;
            window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        }
    }

    function init() {
        const form = document.getElementById('add-student-form');
        if (!form) return;

        document.getElementById('wizardPrev')?.addEventListener('click', function () {
            goToStep(currentStep - 1);
        });

        document.getElementById('wizardNext')?.addEventListener('click', function () {
            if (!validateCurrentStep()) return;
            goToStep(currentStep + 1);
        });

        STEPS.forEach(function (step, i) {
            document.getElementById(step.nav)?.addEventListener('click', function () {
                if (i > currentStep && !validateCurrentStep()) return;
                goToStep(i);
            });
        });

        form.addEventListener('submit', function (e) {
            if (!validateCurrentStep()) {
                e.preventDefault();
                return;
            }
            const allRequired = form.querySelectorAll('[required]');
            let firstInvalid = null;
            allRequired.forEach(function (field) {
                field.classList.remove('is-invalid');
                if (field.type === 'radio' || field.type === 'checkbox') return;
                if (!(field.value || '').trim()) {
                    field.classList.add('is-invalid');
                    if (!firstInvalid) firstInvalid = field;
                }
            });
            if (firstInvalid) {
                e.preventDefault();
                for (let i = 0; i < STEPS.length; i++) {
                    if (getStepEl(i)?.contains(firstInvalid)) {
                        goToStep(i);
                        setTimeout(function () {
                            firstInvalid.focus();
                            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }, 120);
                        break;
                    }
                }
            }
        });

        if (window.errorSectionId) {
            const map = {
                collapseMain: 0,
                collapseEnrollment: 1,
                collapseAddress: 2,
                collapseParents: 3,
                collapseQuota: 4,
                collapseMeals: 4,
                collapseActivity: 4,
                collapseAcademic: 5,
                collapseDisability: 5,
                collapseDynamic: 5
            };
            const step = map[window.errorSectionId];
            if (step !== undefined) currentStep = step;
        }

        initSocialToggles();
        updateUI();
    }

    function syncSocialDetail(checkbox) {
        const targetId = checkbox.getAttribute('data-social-toggle');
        if (!targetId) return;
        const panel = document.getElementById(targetId);
        if (!panel) return;

        panel.style.display = checkbox.checked ? 'block' : 'none';

        if (!checkbox.checked) {
            panel.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(function (input) {
                input.checked = false;
                input.required = false;
            });
            panel.querySelectorAll('select').forEach(function (select) {
                select.value = '';
                select.required = false;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        } else if (checkbox.id === 'orphan' || checkbox.id === 'single_parent_family') {
            panel.querySelectorAll('input[type="radio"]').forEach(function (radio) {
                radio.required = true;
            });
        } else if (checkbox.id === 'sports_section' || checkbox.id === 'art_activity') {
            const select = panel.querySelector('select');
            if (select) select.required = true;
        }
    }

    function initSocialToggles() {
        const root = document.getElementById('wizard-step-social');
        if (!root) return;

        root.querySelectorAll('[data-social-toggle]').forEach(function (checkbox) {
            syncSocialDetail(checkbox);
            checkbox.addEventListener('change', function () {
                syncSocialDetail(checkbox);
            });
        });
    }

    // Legacy hooks used by older inline markup
    window.toggleOrphanOptions = function () {
        const el = document.getElementById('orphan');
        if (el) syncSocialDetail(el);
    };
    window.toggleSingleParentOptions = function () {
        const el = document.getElementById('single_parent_family');
        if (el) syncSocialDetail(el);
    };
    window.toggleOOPOptions = function () {
        const el = document.getElementById('oop');
        if (el) syncSocialDetail(el);
    };
    window.toggleDysfunctionalFamilyOptions = function () {
        const el = document.getElementById('dysfunctional_family');
        if (el) syncSocialDetail(el);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
