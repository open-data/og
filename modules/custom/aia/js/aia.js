(function (Drupal, once, drupalSettings) {
  'use strict';

  const storageKey = 'aia-eia-progress-v1';
  const restoreGuardKey = 'aia-eia-restore-pending';
  const maximumPayloadSize = 2000000;
  const conditionalDelay = 120;
  let conditionalTimer;
  let conditionalTarget;

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
    state.updatedAt = Math.max(Date.now(), Number(state.updatedAt || 0) + 1);
    drupalSettings.aia.state = state;
    storageSet('localStorage', storageKey, JSON.stringify(state));
    return state;
  }

  function updateScore(state) {
    const config = drupalSettings.aia && drupalSettings.aia.scoring;
    if (!config || !config.questions) {
      return;
    }
    let raw = 0;
    let mitigation = 0;
    Object.entries(config.questions).forEach(([name, question]) => {
      const selected = Array.isArray(state.data[name])
        ? state.data[name]
        : [state.data[name]];
      const value = selected.reduce(
        (total, item) => total + Number(question.values[String(item)] || 0),
        0,
      );
      if (Number(question.type) === 2) {
        raw += value;
      }
      else if (Number(question.type) === 3) {
        mitigation += value;
      }
    });
    const maximumRaw = Number(config.maximumRaw || 0);
    const maximumMitigation = Number(config.maximumMitigation || 0);
    const total = mitigation >= 0.8 * (maximumMitigation / 2)
      ? Math.round(0.85 * raw)
      : raw;
    const level = total <= maximumRaw * 0.25
      ? 1
      : total <= maximumRaw * 0.5
        ? 2
        : total <= maximumRaw * 0.75 ? 3 : 4;
    Object.entries({raw, mitigation, total, level}).forEach(([key, value]) => {
      document.querySelectorAll(`[data-aia-score="${key}"]`).forEach((item) => {
        item.textContent = String(value);
      });
    });
    document.querySelectorAll('.aia-current-score').forEach((scoreBar) => {
      Array.from(scoreBar.classList)
        .filter((name) => name.startsWith('aia-current-score--level-'))
        .forEach((name) => scoreBar.classList.remove(name));
      scoreBar.classList.add(`aia-current-score--level-${level}`);
    });
  }

  function queueConditionalRebuild(target) {
    if (!target.matches('select[data-drupal-selector], input[type="radio"][data-drupal-selector], input[type="checkbox"][data-drupal-selector]')) {
      return;
    }
    conditionalTarget = target;
    window.clearTimeout(conditionalTimer);
    conditionalTimer = window.setTimeout(flushConditionalRebuild, conditionalDelay);
  }

  function flushConditionalRebuild() {
    const instances = Drupal.ajax && Drupal.ajax.instances
      ? Drupal.ajax.instances
      : [];
    if (Object.values(instances).some((instance) => instance && instance.ajaxing)) {
      conditionalTimer = window.setTimeout(flushConditionalRebuild, 30);
      return;
    }
    const target = conditionalTarget;
    conditionalTarget = null;
    const liveTarget = target && target.isConnected
      ? target
      : target && target.name
        ? document.getElementsByName(target.name)[0]
        : null;
    if (liveTarget) {
      liveTarget.dispatchEvent(new CustomEvent('aia:conditional-change', {bubbles: true}));
    }
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
    if (!savedState || !hasAnswers(savedState)) {
      return false;
    }
    const savedRevision = Number(savedState.updatedAt || 0);
    if (savedRevision === 0) {
      return !hasAnswers(currentState);
    }
    return savedRevision > Number(currentState.updatedAt || 0);
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

        const snapshot = () => {
          const state = mirrorForm(form);
          updateScore(state);
          return state;
        };
        form.addEventListener('input', snapshot);
        form.addEventListener('change', (event) => {
          snapshot();
          queueConditionalRebuild(event.target);
        });
        form.addEventListener('submit', () => {
          window.clearTimeout(conditionalTimer);
          snapshot();
        }, true);
        window.addEventListener('pagehide', () => {
          window.clearTimeout(conditionalTimer);
          snapshot();
        });
        updateScore(currentState);
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

      once('aia-translations', '#aia-results-translation-form', context).forEach((form) => {
        const snapshotTranslations = () => {
          const saved = parseSavedState(storageGet('localStorage', storageKey));
          if (!saved) {
            return;
          }
          const translations = {};
          new FormData(form).forEach((value, name) => {
            const match = name.match(/^translations\[([^\]]+)\]$/);
            if (match && typeof value === 'string' && value.trim() !== '') {
              translations[match[1]] = value;
            }
          });
          saved.translationsOnResult = translations;
          saved.updatedAt = Math.max(Date.now(), Number(saved.updatedAt || 0) + 1);
          storageSet('localStorage', storageKey, JSON.stringify(saved));
        };
        form.addEventListener('input', snapshotTranslations);
        form.addEventListener('change', snapshotTranslations);
        form.addEventListener('submit', snapshotTranslations, true);
        window.addEventListener('pagehide', snapshotTranslations);
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
