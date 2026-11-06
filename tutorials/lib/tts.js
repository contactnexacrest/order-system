'use strict';
// Text-to-speech with Piper (offline). Every clip is cached by a hash of its text,
// so re-recording a video only synthesizes narration that changed.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const cfg = require('./config');

// Words the voice mispronounces, spelled the way they should sound.
// Subtitles keep the original spelling; only the audio uses this.
const SAY_AS = [
  [/NexaCrest/g, 'Nexa-Crest'],
  [/\bTDS\b/g, 'T D S'],
  [/\bGST\b/g, 'G S T'],
  [/\bGSTIN\b/g, 'G S T I N'],
  [/\bPAN\b/g, 'pan'],
  [/\bDTAA\b/g, 'D T A A'],
  [/\bCSV\b/g, 'C S V'],
  [/\bPDF\b/g, 'P D F'],
  [/\bMD\b/g, 'M D'],
  [/\bRM\b/g, 'R M'],
  [/\bUTR\b/g, 'U T R'],
  [/\bIFSC\b/g, 'I F S C'],
  [/\bSWIFT\b/g, 'swift'],
  [/\bKYC\b/g, 'K Y C'],
  [/\bFOB\b/g, 'F O B'],
  [/\bCIF\b/g, 'C I F'],
  [/\bHS\b/g, 'H S'],
  [/\be-mail/gi, 'email'],
  [/₹\s?([\d,]+)/g, '$1 rupees'],
  [/\b195\b/g, 'one ninety-five'],
  [/\bvs\.?/g, 'versus'],
];

function spoken(text) {
  let t = text;
  for (const [re, rep] of SAY_AS) t = t.replace(re, rep);
  return t;
}

function durationOf(file) {
  const out = execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', file]).toString().trim();
  return Number(out);
}

function synth(text, { voice = cfg.VOICE, lengthScale = Number(process.env.TUT_LENGTH_SCALE || 1.1) } = {}) {
  const dir = path.join(cfg.WORK, 'tts-cache');
  fs.mkdirSync(dir, { recursive: true });
  const words = spoken(text);
  const hash = crypto.createHash('sha1').update(`${path.basename(voice)}|${lengthScale}|${words}`).digest('hex').slice(0, 16);
  const file = path.join(dir, `${hash}.wav`);
  if (!fs.existsSync(file)) {
    if (!fs.existsSync(voice)) throw new Error(`Piper voice not found: ${voice} (see tutorials/README.md)`);
    const tmp = `${file}.tmp.wav`;
    execFileSync('python3', ['-m', 'piper', '-m', voice, '-f', tmp, '--length-scale', String(lengthScale), '--sentence-silence', '0.25'], { input: words, stdio: ['pipe', 'ignore', 'pipe'] });
    fs.renameSync(tmp, file);
  }
  return { file, seconds: durationOf(file) };
}

module.exports = { synth, spoken, durationOf };
