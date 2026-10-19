(function (Drupal, once, drupalSettings) {
  'use strict';

  const storageKey = 'aia-eia-progress-v1';
  const restoreGuardKey = 'aia-eia-restore-pending';
  const maximumPayloadSize = 2000000;

  function storageGet(type, key) {
    try {
      return window[type].getItem(key);
    }
    catch (error) {
      return null;
    }
  }

  function storageSet(type, key, value) {
    try {
      window[type].setItem(key, value);
    }
    catch (error) {
      // Server-side Form API persistence remains available.
    }
  }

  function storageRemove(type, key) {
    try {
      window[type].removeItem(key);
    }
    catch (error) {
      // Nothing to clear when browser storage is unavailable.
    }
  }

  function mirrorForm(form) {
    const state = JSON.parse(JSON.stringify(drupalSettings.aia.state));
    const ignored = new Set([
      'form_build_id',
      'form_token',
      'form_id',
      'op',
      'jump_page',
      'client_restore_payload',
    ]);
    const checkboxValues = {};
    form.querySelectorAll('input[type="checkbox"][name]').forEach((checkbox) => {
      const checkboxName = checkbox.name.match(/^(.+)\[[^\]]+\]$/);
      if (checkboxName) {
        delete state.data[checkboxName[1]];
        checkboxValues[checkboxName[1]] = [];
      }
    });
    new FormData(form).forEach((value, name) => {
      if (ignored.has(name) || value instanceof File || value === '0') {
        return;
      }
      const checkboxName = name.match(/^(.+)\[[^\]]+\]$/);
      if (checkboxName) {
        checkboxValues[checkboxName[1]] ||= [];
        checkboxValues[checkboxName[1]].push(value);
      }
      else {
        state.data[name] = value;
      }
    });
    Object.assign(state.data, checkboxValues);
    state.completed = false;
    storageSet('localStorage', storageKey, JSON.stringify(state));
    return state;
  }

  function parseSavedState(value) {
    if (!value || value.length > maximumPayloadSize) {
      return null;
    }
    try {
      const state = JSON.parse(value);
      return state && typeof state === 'object' && state.data &&
        typeof state.data === 'object' && !Array.isArray(state.data)
        ? state
        : null;
    }
    catch (error) {
      return null;
    }
  }

  function hasAnswers(state) {
    return !!(state && state.data && typeof state.data === 'object' &&
      Object.keys(state.data).length);
  }

  function shouldRestoreBrowserProgress(currentState, savedState) {
    if (!drupalSettings.aia || !drupalSettings.aia.allowRestore) {
      return false;
    }
    if (!savedState || hasAnswers(currentState) || !hasAnswers(savedState)) {
      return false;
    }
    const serverPage = Number(currentState && currentState.currentPage);
    const savedPage = Number(savedState.currentPage);
    return !(Number.isFinite(serverPage) && Number.isFinite(savedPage) &&
      serverPage > savedPage);
  }

  Drupal.behaviors.aiaActions = {
    attach(context) {
      once('aia-browser-state', '#aia-assessment-form', context).forEach((form) => {
        const currentState = drupalSettings.aia.state;
        const savedValue = storageGet('localStorage', storageKey);
        const savedState = parseSavedState(savedValue);
        const restorePending = storageGet('sessionStorage', restoreGuardKey);

        if (restorePending) {
          storageRemove('sessionStorage', restoreGuardKey);
          storageSet('localStorage', storageKey, JSON.stringify(currentState));
        }
        else if (savedValue && !savedState) {
          storageRemove('localStorage', storageKey);
          storageSet('localStorage', storageKey, JSON.stringify(currentState));
        }
        else if (shouldRestoreBrowserProgress(currentState, savedState)) {
          const payload = form.querySelector('[data-aia-restore-payload]');
          const submit = form.querySelector('[data-aia-restore-submit]');
          if (payload && submit) {
            storageSet('sessionStorage', restoreGuardKey, '1');
            payload.value = savedValue;
            submit.click();
            return;
          }
        }
        else if (hasAnswers(currentState) || !hasAnswers(savedState)) {
          storageSet('localStorage', storageKey, JSON.stringify(currentState));
        }

        form.addEventListener('input', () => mirrorForm(form));
        form.addEventListener('change', () => mirrorForm(form));
      });

      once('aia-import', '[data-aia-import-submit]', context).forEach((submit) => {
        const form = submit.closest('form');
        const input = form && form.querySelector('input[type="file"]');
        if (input) {
          input.addEventListener('change', () => {
            if (input.files && input.files.length) {
              submit.click();
            }
          });
        }
      });

      once('aia-jump', '[data-aia-jump-submit]', context).forEach((submit) => {
        const form = submit.closest('form');
        const select = form && form.querySelector('[name="jump_page"]');
        if (select) {
          select.addEventListener('change', () => {
            if (select.value !== '') {
              submit.click();
            }
          });
        }
      });

      once('aia-completed-state', '.aia-results', context).forEach(() => {
        const saved = parseSavedState(storageGet('localStorage', storageKey));
        if (saved) {
          saved.completed = true;
          storageSet('localStorage', storageKey, JSON.stringify(saved));
        }
      });

      once('aia-print', '[data-aia-print]', context).forEach((button) => {
        button.addEventListener('click', () => window.print());
      });

      once('aia-reset', '[data-aia-reset]', context).forEach((button) => {
        button.addEventListener('click', (event) => {
          if (!window.confirm(drupalSettings.aia.confirmRestart || Drupal.t('Delete all answers and start again?'))) {
            event.preventDefault();
          }
          else {
            storageRemove('localStorage', storageKey);
            storageRemove('sessionStorage', restoreGuardKey);
          }
        });
      });

    },
  };
})(Drupal, once, drupalSettings);
