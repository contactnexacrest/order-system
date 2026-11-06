'use strict';
// Turns the raw screen recording + narration clips into the final MP4:
// one narration track (clips placed at their start times), burned-in subtitles,
// H.264/AAC 1080p with faststart.
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const cfg = require('./config');
const { durationOf } = require('./tts');

// ---- audio: place every clip at its start time in one 16-bit PCM buffer ----
function readWav(file) {
  const b = fs.readFileSync(file);
  let off = 12; let fmt = null; let data = null;
  while (off + 8 <= b.length) {
    const id = b.toString('ascii', off, off + 4); const size = b.readUInt32LE(off + 4);
    if (id === 'fmt ') fmt = { channels: b.readUInt16LE(off + 10), rate: b.readUInt32LE(off + 12), bits: b.readUInt16LE(off + 22) };
    if (id === 'data') { data = b.subarray(off + 8, off + 8 + size); break; }
    off += 8 + size + (size % 2);
  }
  if (!fmt || !data || fmt.bits !== 16 || fmt.channels !== 1) throw new Error(`Unsupported wav ${file}`);
  return { rate: fmt.rate, samples: new Int16Array(data.buffer.slice(data.byteOffset, data.byteOffset + data.length)) };
}

function writeWav(file, rate, samples) {
  const h = Buffer.alloc(44);
  h.write('RIFF', 0); h.writeUInt32LE(36 + samples.length * 2, 4); h.write('WAVE', 8);
  h.write('fmt ', 12); h.writeUInt32LE(16, 16); h.writeUInt16LE(1, 20); h.writeUInt16LE(1, 22);
  h.writeUInt32LE(rate, 24); h.writeUInt32LE(rate * 2, 28); h.writeUInt16LE(2, 32); h.writeUInt16LE(16, 34);
  h.write('data', 36); h.writeUInt32LE(samples.length * 2, 40);
  fs.writeFileSync(file, Buffer.concat([h, Buffer.from(samples.buffer, samples.byteOffset, samples.length * 2)]));
}

function buildAudio(clips, seconds, file) {
  const rate = clips.length ? readWav(clips[0].file).rate : 22050;
  const out = new Int16Array(Math.ceil((seconds + 1) * rate));
  for (const c of clips) {
    const { samples } = readWav(c.file);
    const at = Math.round(c.start * rate);
    for (let i = 0; i < samples.length && at + i < out.length; i++) out[at + i] = samples[i];
  }
  writeWav(file, rate, out);
}

// ---- subtitles ----
function ts(s) {
  const cs = Math.max(0, Math.round(s * 100));
  const h = Math.floor(cs / 360000); const m = Math.floor((cs % 360000) / 6000); const sec = Math.floor((cs % 6000) / 100);
  return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}.${String(cs % 100).padStart(2, '0')}`;
}

// Split narration into subtitle-sized pieces (sentence boundaries, then commas).
function chunks(text, max = 68) {
  const sentences = text.replace(/\s+/g, ' ').trim().match(/[^.!?]+[.!?]+["')]*|[^.!?]+$/g) || [text];
  const out = [];
  for (let s of sentences) {
    s = s.trim();
    while (s.length > max) {
      let cut = s.lastIndexOf(', ', max); if (cut < max * 0.4) cut = s.lastIndexOf(' ', max);
      out.push(s.slice(0, cut + 1).trim()); s = s.slice(cut + 1).trim();
    }
    if (s) {
      const last = out[out.length - 1];
      if (last && last.length + s.length + 1 <= max && last.length < 40) out[out.length - 1] = `${last} ${s}`; else out.push(s);
    }
  }
  return out;
}

function buildAss(clips, file) {
  const head = `[Script Info]
ScriptType: v4.00+
PlayResX: ${cfg.VIDEO.width}
PlayResY: ${cfg.VIDEO.height}
WrapStyle: 0
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Default,Inter,40,&H00FFFFFF,&H00FFFFFF,&H50200A00,&H50200A00,0,0,0,0,100,100,0,0,3,14,0,2,260,260,34,1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
`;
  const lines = [];
  for (const c of clips) {
    const parts = chunks(c.text);
    const total = parts.reduce((n, p) => n + p.length, 0) || 1;
    let t = c.start;
    for (const p of parts) {
      const d = c.seconds * (p.length / total);
      lines.push(`Dialogue: 0,${ts(t)},${ts(t + d)},Default,,0,0,0,,${p.replace(/[{}]/g, '').replace(/\n/g, ' ')}`);
      t += d;
    }
  }
  fs.writeFileSync(file, head + lines.join('\n') + '\n');
}

function srtTime(s) {
  const ms = Math.max(0, Math.round(s * 1000));
  const h = Math.floor(ms / 3600000); const m = Math.floor((ms % 3600000) / 60000); const sec = Math.floor((ms % 60000) / 1000);
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')},${String(ms % 1000).padStart(3, '0')}`;
}

function buildSrt(clips, file) {
  let n = 0; const out = [];
  for (const c of clips) {
    const parts = chunks(c.text);
    const total = parts.reduce((k, p) => k + p.length, 0) || 1;
    let t = c.start;
    for (const p of parts) {
      const d = c.seconds * (p.length / total);
      out.push(`${++n}\n${srtTime(t)} --> ${srtTime(t + d)}\n${p}\n`); t += d;
    }
  }
  fs.writeFileSync(file, out.join('\n'));
}

async function buildVideo({ webm, clips: rawClips, out, workDir }) {
  fs.writeFileSync(path.join(workDir, 'clips.json'), JSON.stringify({ webm, clips: rawClips }, null, 1));
  // The browser shows a blank page for a few seconds before the first card; start the video
  // half a second before the first line instead (narration and subtitles move with it).
  const first = rawClips.length ? Math.min(...rawClips.map((c) => c.start)) : 0;
  const lead = Math.max(0, first - 0.5);
  const clips = rawClips.map((c) => ({ ...c, start: c.start - lead }));
  const seconds = durationOf(webm) - lead;
  const wav = path.join(workDir, 'narration.wav');
  const ass = path.join(workDir, 'subs.ass');
  buildAudio(clips, seconds, wav);
  buildAss(clips, ass);
  buildSrt(clips, out.replace(/\.mp4$/, '.srt'));
  const assArg = ass.replace(/\\/g, '/').replace(/:/g, '\\:').replace(/'/g, "\\'");
  execFileSync('ffmpeg', ['-y', '-v', 'error', '-ss', lead.toFixed(3), '-i', webm, '-i', wav,
    '-vf', `fps=30,scale=${cfg.VIDEO.width}:${cfg.VIDEO.height}:flags=lanczos,ass='${assArg}'`,
    '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-pix_fmt', 'yuv420p', '-profile:v', 'high',
    '-c:a', 'aac', '-b:a', '160k', '-ar', '44100', '-ac', '2',
    '-map', '0:v:0', '-map', '1:a:0', '-t', String(seconds),
    '-movflags', '+faststart', out], { stdio: ['ignore', 'inherit', 'inherit'] });
  return durationOf(out);
}

module.exports = { buildVideo, chunks, buildAss, buildAudio };
