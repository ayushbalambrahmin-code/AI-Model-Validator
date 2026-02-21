(function () {
  const btn = document.getElementById("mv-start-run");
  if (!btn) return;

  const cfg = window.MVPortal || {};
  const startRunUrl = cfg.startRunUrl || "";
  const runStatusUrl = cfg.runStatusUrl || "";
  const resetRunUrl = cfg.resetRunUrl || "";
  const restNonce = cfg.restNonce || "";

  if (!startRunUrl || !runStatusUrl || !restNonce) {
    return;
  }

  const pill = document.getElementById("mv-run-pill");
  const progressBox = document.getElementById("mv-progress");
  const progressFill = document.getElementById("mv-progress-fill");
  const progressPercent = document.getElementById("mv-progress-percent");
  const progressStage = document.getElementById("mv-progress-stage");
  const caseId = btn.getAttribute("data-case");
  const runId = btn.getAttribute("data-run");
  const statusClassNames = [
    "mv-pill--queued",
    "mv-pill--running",
    "mv-pill--done",
    "mv-pill--failed",
  ];

  const stages = [
    "Uploading document...",
    "Extracting content...",
    "Running compliance checks...",
    "Generating report...",
  ];

  let progressValue = 0;
  let progressTimer = null;

  function showProgress() {
    if (progressBox) {
      progressBox.classList.remove("mv-progress--hidden");
    }
  }

  function stageFor(value) {
    if (value < 20) return stages[0];
    if (value < 45) return stages[1];
    if (value < 75) return stages[2];
    return stages[3];
  }

  function renderProgress(value, force) {
    const next = Math.max(0, Math.min(100, value));
    if (!force && next < progressValue) {
      return;
    }
    progressValue = next;
    if (progressFill) progressFill.style.width = `${next}%`;
    if (progressPercent) progressPercent.textContent = `${Math.floor(next)}%`;
    if (progressStage) {
      progressStage.textContent = next >= 100 ? "Completed" : stageFor(next);
    }
  }

  function startProgressAnimation() {
    showProgress();
    if (progressValue < 8) {
      renderProgress(8, true);
    }
    if (progressTimer) {
      return;
    }
    progressTimer = window.setInterval(function () {
      if (progressValue >= 85) {
        return;
      }
      let step = 3;
      if (progressValue < 30) step = 7;
      else if (progressValue < 60) step = 4;
      renderProgress(Math.min(85, progressValue + step), true);
    }, 1200);
  }

  function stopProgressAnimation(status) {
    if (progressTimer) {
      window.clearInterval(progressTimer);
      progressTimer = null;
    }
    if (status === "done") {
      renderProgress(100, true);
    } else if (status === "failed" && progressStage) {
      progressStage.textContent = "Needs retry";
    }
  }

  function applyBackendProgress(progress) {
    if (!progress || typeof progress !== "object") {
      return;
    }

    if (typeof progress.progress_percent === "number") {
      renderProgress(progress.progress_percent, true);
    }

    if (progressStage) {
      let stageText = "";
      if (typeof progress.status_text === "string" && progress.status_text.trim() !== "") {
        stageText = progress.status_text.trim();
      } else if (
        typeof progress.evaluated_rules === "number" &&
        typeof progress.total_rules === "number" &&
        progress.total_rules > 0
      ) {
        stageText = `Running (${progress.evaluated_rules}/${progress.total_rules})`;
      }

      if (
        typeof progress.eta_seconds === "number" &&
        progress.eta_seconds > 0 &&
        String(stageText).toLowerCase().indexOf("completed") === -1
      ) {
        stageText += ` • ETA ~${progress.eta_seconds}s`;
      }

      if (stageText) {
        progressStage.textContent = stageText;
      }
    }
  }

  function statusUi(status) {
    const key = String(status || "").toLowerCase();
    if (key === "running") return { label: "Processing", klass: "mv-pill--running" };
    if (key === "done") return { label: "Completed", klass: "mv-pill--done" };
    if (key === "failed") return { label: "Needs retry", klass: "mv-pill--failed" };
    return { label: "Queued", klass: "mv-pill--queued" };
  }

  function applyStatus(status) {
    if (!pill) return;
    const ui = statusUi(status);
    pill.textContent = ui.label;
    statusClassNames.forEach(function (cls) {
      pill.classList.remove(cls);
    });
    pill.classList.add(ui.klass);
  }

  async function poll() {
    try {
      const r = await fetch(
        `${runStatusUrl}?run_id=${encodeURIComponent(String(runId || ""))}`,
        {
          headers: { "X-WP-Nonce": restNonce },
        }
      );
      const j = await r.json();
      if (j && j.ok && j.run) {
        const status = String(j.run.status || "").toLowerCase();
        applyStatus(status);
        applyBackendProgress(j.run.progress || null);
        if (status === "running") {
          startProgressAnimation();
        }
        if (status === "done" || status === "failed") {
          stopProgressAnimation(status);
          window.setTimeout(function () {
            window.location.reload();
          }, status === "done" ? 350 : 150);
          return;
        }
      }
    } catch (e) {}
    window.setTimeout(poll, 4000);
  }

  btn.addEventListener("click", async function () {
    btn.disabled = true;
    applyStatus("running");
    startProgressAnimation();

    try {
      const r = await fetch(startRunUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": restNonce,
        },
        body: JSON.stringify({ case_id: caseId }),
      });
      const j = await r.json();
      console.log("Start run response:", j);
      if (!j || !j.ok) {
        window.alert("We could not start analysis right now. Please try again.");
        stopProgressAnimation("failed");
        btn.disabled = false;
        window.location.reload();
        return;
      }
      poll();
    } catch (e) {
      window.alert("We could not start analysis right now. Please try again.");
      stopProgressAnimation("failed");
      btn.disabled = false;
    }
  });

  const tryAgain = document.getElementById("mv-try-again");
  if (tryAgain) {
    tryAgain.addEventListener("click", function () {
      if (!btn.disabled) {
        btn.click();
      }
    });
  }

  const reset = document.getElementById("mv-reset-run");
  if (reset && resetRunUrl) {
    reset.addEventListener("click", async function () {
      reset.disabled = true;
      try {
        const r = await fetch(resetRunUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": restNonce,
          },
          body: JSON.stringify({ run_id: parseInt(String(runId || "0"), 10) }),
        });
        const j = await r.json();
        if (!j || !j.ok) {
          window.alert("Reset failed. Please refresh and try again.");
          reset.disabled = false;
          return;
        }
        window.location.reload();
      } catch (e) {
        window.alert("Reset failed. Please refresh and try again.");
        reset.disabled = false;
      }
    });
  }

  if (
    pill &&
    pill.textContent &&
    pill.textContent.trim().toLowerCase() === "processing"
  ) {
    startProgressAnimation();
    poll();
  }
})();
