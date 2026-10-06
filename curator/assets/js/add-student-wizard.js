(function () {
    'use strict';

    const STEPS = [
        { id: 'wizard-step-main', nav: 'nav-main', badge: 'main-badge', label: 'Личные данные' },
        { id: 'wizard-step-enrollment', nav: 'nav-enrollment', badge: 'enrollment-badge', label: 'Зачисление' },
        { id: 'wizard-step-address', nav: 'nav-address', badge: 'address-badge', label: 'Адрес' },
        { id: 'wizard-step-parents', nav: 'nav-parents', badge: 'parents-badge', label: 'Семья' },
        { id: 'wizard-step-social', nav: 'nav-social', badge: null, label: 'Социальное' },
        { id: 'wizard-step-extra', nav: 'nav-extra', badge: null, label: 'Дополнительно' }
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
        const pct = ((currentStep + 1) / STEPS.length) * 100;
        if (progress) progress.style.width = pct + '%';
        if (progressText) {
            progressText.textContent = 'Шаг ' + (currentStep + 1) + ' из ' + STEPS.length + ' — ' + STEPS[currentStep].label;
        }

        const prevBtn = document.getElementById('wizardPrev');
        const nextBtn = document.getElementById('wizardNext');
        const submitBtn = document.getElementById('wizardSubmit');

        if (prevBtn) prevBtn.disabled = currentStep === 0;
        if (nextBtn) nextBtn.classList.toggle('d-none', currentStep === STEPS.length - 1);
        if (submitBtn) submitBtn.classList.toggle('d-none', currentStep !== STEPS.length - 1);
    }

    function goToStep(index) {
        if (index < 0 || index >= STEPS.length) return;
        currentStep = index;
        updateUI();
        const el = getStepEl(currentStep);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
                        firstInvalid.focus();
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

        updateUI();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
