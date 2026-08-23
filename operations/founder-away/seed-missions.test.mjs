import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const plan = JSON.parse(await readFile(new URL('./seed-missions.json', import.meta.url), 'utf8'));
const allowedTags = new Set(['research', 'code', 'creative', 'community', 'onchain', 'operations', 'moderation', 'data']);

test('seed mission plan is useful but carries no signing or publication authority', () => {
  assert.equal(plan.status, 'unsigned-drafts-require-current-owner-review');
  assert.equal(plan.targetLifetimeDays, 60);
  assert.match(plan.publicationBoundary, /current NFH owner must prepare, review, and sign each exact message/i);
  assert.equal(plan.missions.length, 3);
  for (const mission of plan.missions) {
    assert.equal(Object.hasOwn(mission, 'signature'), false);
    assert.equal(Object.hasOwn(mission, 'ownerAddress'), false);
    assert.equal(Object.hasOwn(mission, 'expiresAt'), false);
  }
});

test('every draft fits the structured Agent Wanted bounds', () => {
  assert.equal(new Set(plan.missions.map((mission) => mission.suggestedTokenId)).size, plan.missions.length,
    'each simultaneously published draft needs a distinct token because the feed keeps only the latest mission per token');
  for (const mission of plan.missions) {
    assert.ok(Number.isInteger(mission.suggestedTokenId) && mission.suggestedTokenId >= 0 && mission.suggestedTokenId <= 9999);
    assert.equal(mission.missionKind, 'open_edition');
    assert.equal(mission.maxAgents, null);
    assert.equal(mission.rewardType, 'fun');
    assert.ok(mission.task.length >= 4 && mission.task.length <= 140);
    assert.ok(mission.constraints.length >= 1 && mission.constraints.length <= 160);
    assert.ok(mission.capabilityTags.length >= 1 && mission.capabilityTags.length <= 3);
    assert.ok(mission.capabilityTags.every((tag) => allowedTags.has(tag)));
  }
});
