import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const file = path.join(__dirname, '..', 'paxdesign-toolbar', 'assets', 'js', 'dock.js');
let c2 = fs.readFileSync(file, 'utf8');

const fastPat =
  /      if \(cfg\.fast\) \{[\s\S]*?        return;\r?\n      \}\r?\n\r?\n      setBtnBusy\(btn, true, cfg\.busyLabel \|\| 'Running…'\);\r?\n/;
const repl =
  "      var mod = cfg.module || 'osint';\r\n      var startedAt = Date.now();\r\n      var minMs = minDisplayForPipeline(cfg);\r\n\r\n      setBtnBusy(btn, true, cfg.busyLabel || 'Running…');\r\n";
const m = c2.match(fastPat);
console.log('fast removed:', !!m);
c2 = c2.replace(fastPat, repl);

const patches = [
  [
    `      var apiDone = false;
      var pipelineDone = false;
      var apiData = null;
      var finished = false;

      function finish() {
        if (finished) return;
        finished = true;
        setBtnBusy(btn, false);`,
    `      var apiDone = false;
      var pipelineDone = false;
      var minDone = false;
      var apiData = null;
      var finished = false;

      function finish() {
        if (finished) return;
        finished = true;
        if (typeof win.pdxStopAiStageRotator === 'function') {
          win.pdxStopAiStageRotator('#' + cfg.id);
        }
        setBtnBusy(btn, false);`,
  ],
  [
    `      runDeepPipeline(cfg.id, cfg.stages, {
        logLines: cfg.logLines,
        speed: cfg.speed,
        findings: cfg.findings,
        onStage: cfg.onStage,
      }).then(function () {
        pipelineDone = true;
        if (apiDone) finish();
      });

      Promise.resolve(cfg.api()).then(function (data) {
        apiData = data;
        apiDone = true;
        if (pipelineDone) finish();
      }).catch(function () {
        apiData = null;
        apiDone = true;
        if (pipelineDone) finish();
      });`,
    `      function tryFinish() {
        if (!apiDone || !pipelineDone || !minDone) return;
        finish();
      }

      runDeepPipeline(cfg.id, cfg.stages, {
        logLines: cfg.logLines,
        speed: pipelineSpeedForModule(mod, cfg.speed),
        findings: cfg.findings,
        onStage: cfg.onStage,
        module: mod,
      }).then(function () {
        pipelineDone = true;
        tryFinish();
      });

      whenMinDisplayElapsed(startedAt, minMs).then(function () {
        minDone = true;
        tryFinish();
      });

      Promise.resolve(cfg.api()).then(function (data) {
        apiData = data;
        apiDone = true;
        tryFinish();
      }).catch(function () {
        apiData = null;
        apiDone = true;
        tryFinish();
      });`,
  ],
];

for (const [a, b] of patches) {
  if (c2.includes(a)) {
    c2 = c2.replace(a, b);
    console.log('patched block');
  } else console.log('miss block');
}

c2 = c2.replace("module: cfg.module || 'osint',", "module: mod,", 1);
c2 = c2.replace(
  "var speed = typeof opts.speed === 'number' ? opts.speed : PDX_PIPELINE_SPEED;",
  "var speed = typeof opts.speed === 'number' ? opts.speed : pipelineSpeedForModule(opts.module, null);",
  1
);

if (!c2.includes('pdxWireAiStageRotatorInPipeline')) {
  c2 = c2.replace(
    '      return new Promise(function(resolve) {',
    `      if (typeof win.pdxWireAiStageRotatorInPipeline === 'function') {
        win.pdxWireAiStageRotatorInPipeline(pipelineId, stages.map(function (s) { return s.label; }));
      }

      return new Promise(function(resolve) {`,
    1
  );
  console.log('rotator');
}

c2 = c2.replace(
  "module: 'threat',\n        id: 'pdx-corr-pipeline'",
  "module: 'investigation',\n        id: 'pdx-corr-pipeline'"
);

c2 = c2.replace(
  "detail.innerHTML = buildDeepPipeline('pdx-graph-pipeline', graphStages, {\n        title: 'Infrastructure Graph — ' + value, showLog: true,\n      });",
  "detail.innerHTML = buildDeepPipeline('pdx-graph-pipeline', graphStages, {\n        title: 'Infrastructure Graph — ' + value, showLog: true, module: 'graph',\n      });"
);

const oldGraph = `      var apiDone = false, pipelineDone = false, apiData = null;
      runDeepPipeline('pdx-graph-pipeline', graphStages, { logLines: graphLogLines }).then(function() {
        pipelineDone = true;
        if (apiDone) finalizeGraph(canvas, detail, controls, apiData, value);
      });
      apiFetch('POST', '/intel/correlate', { value: value }).then(function(data) {
        apiData = data; apiDone = true;
        if (pipelineDone) finalizeGraph(canvas, detail, controls, data, value);
      });`;

const newGraph = `      var apiDone = false, pipelineDone = false, minDone = false, apiData = null;
      var graphStarted = Date.now();
      function tryGraphFinish() {
        if (!apiDone || !pipelineDone || !minDone) return;
        finalizeGraph(canvas, detail, controls, apiData, value);
      }
      runDeepPipeline('pdx-graph-pipeline', graphStages, { logLines: graphLogLines, module: 'graph' }).then(function() {
        pipelineDone = true;
        tryGraphFinish();
      });
      whenMinDisplayElapsed(graphStarted, PDX_INTEL_MIN_DISPLAY_MS).then(function () {
        minDone = true;
        tryGraphFinish();
      });
      apiFetch('POST', '/intel/correlate', { value: value }).then(function(data) {
        apiData = data; apiDone = true;
        tryGraphFinish();
      });`;

if (c2.includes(oldGraph)) {
  c2 = c2.replace(oldGraph, newGraph);
  console.log('graph');
}

c2 = c2.replace(
  "res.innerHTML = buildDeepPipeline('pdx-cve-pipeline', cveStages, { title: 'CVE Analysis — ' + q, showLog: true });",
  "res.innerHTML = buildDeepPipeline('pdx-cve-pipeline', cveStages, { title: 'CVE Analysis — ' + q, showLog: true, module: 'threat' });"
);
c2 = c2.replace(
  "runDeepPipeline('pdx-cve-pipeline', cveStages, {",
  "runDeepPipeline('pdx-cve-pipeline', cveStages, { module: 'threat',"
);
c2 = c2.replace(
  "res.innerHTML = buildDeepPipeline('pdx-surf-pipeline', surfStages, { title: 'Attack Surface — ' + domain, showLog: true });",
  "res.innerHTML = buildDeepPipeline('pdx-surf-pipeline', surfStages, { title: 'Attack Surface — ' + domain, showLog: true, module: 'threat' });"
);
c2 = c2.replace(
  "runDeepPipeline('pdx-surf-pipeline', surfStages, {",
  "runDeepPipeline('pdx-surf-pipeline', surfStages, { module: 'threat',"
);

fs.writeFileSync(file, c2, 'utf8');
console.log('wrote', file);
