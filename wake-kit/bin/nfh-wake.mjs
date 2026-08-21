import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { createWakePacket, renderMission } from '../src/wake.mjs';
import { createReceipt, renderReceipt } from '../src/receipt.mjs';

function usage() {
  return `NFH Wake Kit

Wake a verified public identity:
  npm run wake -- --token 1003 --task "Map three useful MCP integrations"

Hash a completed local result into an honest receipt:
  npm run receipt -- --packet .wake/nfh-1003/wake.json --result ./result.md \\
    --summary "Mapped three integrations with public sources" --source https://example.com/source
`;
}

function parseArgs(argv) {
  const command = argv[0];
  const values = { source: [] };
  for (let index = 1; index < argv.length; index += 1) {
    const flag = argv[index];
    if (!flag.startsWith('--')) throw new Error(`Unexpected argument: ${flag}`);
    const key = flag.slice(2);
    const value = argv[index + 1];
    if (!value || value.startsWith('--')) throw new Error(`Missing value for --${key}.`);
    if (key === 'source') values.source.push(value);
    else values[key] = value;
    index += 1;
  }
  return { command, values };
}

async function writeJson(path, value) {
  await mkdir(dirname(path), { recursive: true });
  await writeFile(path, `${JSON.stringify(value, null, 2)}\n`, { flag: 'wx' });
}

async function wake(values) {
  if (!values.token || !values.task) throw new Error('wake requires --token and --task.');
  const packet = await createWakePacket(
    { tokenId: values.token, task: values.task },
    { endpoint: values.endpoint },
  );
  const outputDir = resolve(values.out || `.wake/nfh-${packet.tokenId}`);
  await mkdir(outputDir, { recursive: true });
  await writeJson(resolve(outputDir, 'wake.json'), packet);
  await writeFile(resolve(outputDir, 'mission.md'), renderMission(packet), { flag: 'wx' });
  process.stdout.write(`Woke NFH #${packet.tokenId}\n${outputDir}/mission.md\n`);
}

async function receipt(values) {
  if (!values.packet || !values.result || !values.summary) {
    throw new Error('receipt requires --packet, --result, and --summary.');
  }
  const packetPath = resolve(values.packet);
  const resultPath = resolve(values.result);
  const packet = JSON.parse(await readFile(packetPath, 'utf8'));
  const resultBytes = await readFile(resultPath);
  const record = createReceipt({
    packet,
    resultBytes,
    resultPath,
    summary: values.summary,
    sources: values.source,
  });
  const outputDir = resolve(values.out || dirname(packetPath));
  await mkdir(outputDir, { recursive: true });
  await writeJson(resolve(outputDir, 'receipt.json'), record);
  await writeFile(resolve(outputDir, 'receipt.md'), renderReceipt(record), { flag: 'wx' });
  process.stdout.write(`Created ${record.status} receipt\n${outputDir}/receipt.md\n`);
}

try {
  const { command, values } = parseArgs(process.argv.slice(2));
  if (command === 'wake') await wake(values);
  else if (command === 'receipt') await receipt(values);
  else {
    process.stdout.write(usage());
    process.exitCode = command ? 1 : 0;
  }
} catch (error) {
  process.stderr.write(`Error: ${error.message}\n\n${usage()}`);
  process.exitCode = 1;
}
