import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const file = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'paxdesign-toolbar', 'assets', 'js', 'dock.js');
let c = fs.readFileSync(file, 'utf8');
const nl = c.includes('\r\n') ? '\r\n' : '\n';

const oldFinish = [
  '      var apiDone = false;',
  '      var pipelineDone = false;',
  '      var apiData = null;',
  '      var finished = false;',
  '',
  '      function finish() {',
  '        if (finished) return;',
  '        finished = true;',
  '        setBtnBusy(btn, false);',
].join(nl);

const newFinish = [
  '      var apiDone = false;',
  '      var pipelineDone = false;',
  '      var minDone = false;',
  '      var apiData = null;',
  '      var finished = false;',
  '',
  '      function finish() {',
  '        if (finished) return;',
  '        finished = true;',
  "        if (typeof win.pdxStopAiStageRotator === 'function') {",
  "          win.pdxStopAiStageRotator('#' + cfg.id);",
  '        }',
  '        setBtnBusy(btn, false);',
].join(nl);

const oldRun = [
  '      runDeepPipeline(cfg.id, cfg.stages, {',
  '        logLines: cfg.logLines,',
  '        speed: cfg.speed,',
  '        findings: cfg.findings,',
  '        onStage: cfg.onStage,',
  '      }).then(function () {',
  '        pipelineDone = true;',
  '        if (apiDone) finish();',
  '      });',
  '',
  '      Promise.resolve(cfg.api()).then(function (data) {',
  '        apiData = data;',
  '        apiDone = true;',
  '        if (pipelineDone) finish();',
  '      }).catch(function () {',
  '        apiData = null;',
  '        apiDone = true;',
  '        if (pipelineDone) finish();',
  '      });',
].join(nl);

const newRun = [
  '      function tryFinish() {',
  '        if (!apiDone || !pipelineDone || !minDone) return;',
  '        finish();',
  '      }',
  '',
  '      runDeepPipeline(cfg.id, cfg.stages, {',
  '        logLines: cfg.logLines,',
  '        speed: pipelineSpeedForModule(mod, cfg.speed),',
  '        findings: cfg.findings,',
  '        onStage: cfg.onStage,',
  '        module: mod,',
  '      }).then(function () {',
  '        pipelineDone = true;',
  '        tryFinish();',
  '      });',
  '',
  '      whenMinDisplayElapsed(startedAt, minMs).then(function () {',
  '        minDone = true;',
  '        tryFinish();',
  '      });',
  '',
  '      Promise.resolve(cfg.api()).then(function (data) {',
  '        apiData = data;',
  '        apiDone = true;',
  '        tryFinish();',
  '      }).catch(function () {',
  '        apiData = null;',
  '        apiDone = true;',
  '        tryFinish();',
  '      });',
].join(nl);

console.log('finish', c.includes(oldFinish));
if (c.includes(oldFinish)) c = c.replace(oldFinish, newFinish);
console.log('run', c.includes(oldRun));
if (c.includes(oldRun)) c = c.replace(oldRun, newRun);

fs.writeFileSync(file, c, 'utf8');
console.log('wrote');
