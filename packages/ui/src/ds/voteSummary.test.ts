import assert from 'node:assert/strict'
import { test } from 'node:test'
import { formatApproval, formatNet, meterSegments, summarizeVotes } from './voteSummary.ts'

test('concept examples: net and rounded approval', () => {
  const cases: Array<[number, number, number, number]> = [
    [24, 6, 18, 80],
    [18, 12, 6, 60],
    [21, 3, 18, 88],
  ]
  for (const [up, down, net, approval] of cases) {
    const s = summarizeVotes(up, down)
    assert.equal(s.net, net)
    assert.equal(s.approvalRounded, approval)
    assert.equal(s.total, up + down)
  }
})

test('approval is computed unrounded and only rounded for display', () => {
  const s = summarizeVotes(2, 1)
  assert.ok(Math.abs((s.approval ?? 0) - 66.6667) < 0.001)
  assert.equal(s.approvalRounded, 67)
})

test('no votes yields no approval and no meter', () => {
  const s = summarizeVotes(0, 0)
  assert.equal(s.approval, null)
  assert.equal(s.approvalRounded, null)
  assert.equal(s.net, 0)
  assert.equal(meterSegments(s), null)
  assert.equal(formatApproval(s.approvalRounded), null)
})

test('one-sided votes are 0 % / 100 %, not a missing value', () => {
  assert.equal(summarizeVotes(0, 4).approvalRounded, 0)
  assert.equal(summarizeVotes(4, 0).approvalRounded, 100)
})

test('invalid counts are rejected', () => {
  for (const [up, down] of [
    [-1, 0],
    [1.5, 0],
    [0, Number.NaN],
    [Number.POSITIVE_INFINITY, 1],
  ]) {
    assert.throws(() => summarizeVotes(up, down), RangeError)
  }
})

test('net uses a typographic minus and an explicit plus', () => {
  assert.equal(formatNet(18), '+18')
  assert.equal(formatNet(-6), '−6')
  assert.equal(formatNet(0), '0')
  assert.equal(formatNet(1200, 'de'), '+1.200')
  assert.equal(formatNet(1200, 'en'), '+1,200')
})

test('approval formatting per locale', () => {
  assert.equal(formatApproval(80, 'de'), '80 %')
  assert.equal(formatApproval(80, 'en'), '80%')
})

test('meter segments add up to one', () => {
  const m = meterSegments(summarizeVotes(18, 12))
  assert.deepEqual(m, { up: 0.6, down: 0.4 })
})

// Owner decision L-03 (2026-10-22): a displayed approval never reads 100 % while a
// down vote exists, and never 0 % while an up vote exists. Zero votes stay unset.
test('L-03: approval never shows 100 % while down votes exist', () => {
  assert.equal(summarizeVotes(199, 1).approvalRounded, 99)
  assert.equal(summarizeVotes(1999, 1).approvalRounded, 99)
  assert.equal(summarizeVotes(1, 0).approvalRounded, 100)
})

test('L-03: approval never shows 0 % while up votes exist', () => {
  assert.equal(summarizeVotes(1, 300).approvalRounded, 1)
  assert.equal(summarizeVotes(1, 1000).approvalRounded, 1)
  assert.equal(summarizeVotes(0, 5).approvalRounded, 0)
})

test('L-03: zero votes stay without a percentage', () => {
  const s = summarizeVotes(0, 0)
  assert.equal(s.approval, null)
  assert.equal(s.approvalRounded, null)
})

test('L-03: boundary cases keep the plain rounding', () => {
  assert.equal(summarizeVotes(1, 1).approvalRounded, 50)
  assert.equal(summarizeVotes(2, 1).approvalRounded, 67)
  assert.equal(summarizeVotes(499, 501).approvalRounded, 50)
  assert.equal(summarizeVotes(99, 1).approvalRounded, 99)
  assert.equal(summarizeVotes(1, 99).approvalRounded, 1)
  // round(0.5) = 1 already; round(0.4975) = 0 must still show 1
  assert.equal(summarizeVotes(1, 201).approvalRounded, 1)
})

test('L-03: the unrounded approval is untouched by the display guard', () => {
  assert.equal(summarizeVotes(199, 1).approval, 99.5)
  assert.equal(summarizeVotes(1, 300).approval, (1 / 301) * 100)
})

test('low participation: fewer than 10 votes, not for zero votes or at the threshold', () => {
  assert.equal(summarizeVotes(0, 0).fewVotes, false)
  assert.equal(summarizeVotes(4, 1).fewVotes, true)
  assert.equal(summarizeVotes(6, 3).fewVotes, true)
  assert.equal(summarizeVotes(7, 3).fewVotes, false)
  assert.equal(summarizeVotes(146, 12).fewVotes, false)
})

test('P-02: boundary at 10 votes and at 5 voters (fewer than, not up to)', () => {
  // 9 votes: below the vote threshold
  assert.equal(summarizeVotes(5, 4).fewVotes, true)
  // exactly 10 votes, 10 voters: no marker
  assert.equal(summarizeVotes(6, 4).fewVotes, false)
  assert.equal(summarizeVotes(6, 4, 10).fewVotes, false)
  // exactly 5 voters with 10 votes: no marker
  assert.equal(summarizeVotes(6, 4, 5).fewVotes, false)
  // 4 voters with 10 votes (voter rule alone): marker
  assert.equal(summarizeVotes(6, 4, 4).fewVotes, true)
  assert.equal(summarizeVotes(6, 4, 4).voters, 4)
})

test('P-02: the voter rule applies when voters < 5 even with ≥10 votes', () => {
  assert.equal(summarizeVotes(50, 50, 4).fewVotes, true)
  assert.equal(summarizeVotes(50, 50, 5).fewVotes, false)
  assert.equal(summarizeVotes(50, 50).voters, 100)
})

test('P-02: zero votes never carry the marker, even with zero voters', () => {
  assert.equal(summarizeVotes(0, 0, 0).fewVotes, false)
  assert.equal(summarizeVotes(0, 0).voters, 0)
})

test('P-02: only guest votes count as voters and follow the same rule', () => {
  // four guest votes, no account: few votes
  assert.equal(summarizeVotes(3, 1).fewVotes, true)
  assert.equal(summarizeVotes(3, 1).voters, 4)
})

test('P-02: voter counts outside 0..total are data errors', () => {
  assert.throws(() => summarizeVotes(2, 1, 4), RangeError)
  assert.throws(() => summarizeVotes(2, 1, -1), RangeError)
  assert.throws(() => summarizeVotes(2, 1, 1.5), RangeError)
  assert.throws(() => summarizeVotes(0, 0, 1), RangeError)
})
