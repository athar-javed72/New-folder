#!/usr/bin/env node

/**
 * Ralph Loop CLI Helper for Antigravity
 * Manages PRD.md, progress.txt, and iteration states.
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const PRD_FILE = 'PRD.md';
const PROGRESS_FILE = 'progress.txt';

function getPRDPath() {
  return path.resolve(process.cwd(), PRD_FILE);
}

function getProgressPath() {
  return path.resolve(process.cwd(), PROGRESS_FILE);
}

function init() {
  const prdPath = getPRDPath();
  const progPath = getProgressPath();

  if (!fs.existsSync(prdPath)) {
    const templatePRD = `# Project Requirements Document (PRD)

## Overview
Brief description of the project and end goal.

## Tasks
- [ ] Task 1: Project structure setup and dependencies
- [ ] Task 2: Core data models and utilities
- [ ] Task 3: Implementation of main features
- [ ] Task 4: Automated tests and validation
- [ ] Task 5: Final documentation and polish
`;
    fs.writeFileSync(prdPath, templatePRD, 'utf8');
    console.log('✓ Created PRD.md template');
  } else {
    console.log('ℹ PRD.md already exists');
  }

  if (!fs.existsSync(progPath)) {
    const templateProgress = `Ralph Loop Progress Log
========================
Created: ${new Date().toISOString()}

Completed Tasks:
----------------
`;
    fs.writeFileSync(progPath, templateProgress, 'utf8');
    console.log('✓ Created progress.txt log');
  } else {
    console.log('ℹ progress.txt already exists');
  }
}

function getTasks() {
  const prdPath = getPRDPath();
  if (!fs.existsSync(prdPath)) return [];
  const content = fs.readFileSync(prdPath, 'utf8');
  const lines = content.split('\n');
  const tasks = [];

  for (const line of lines) {
    const unchecked = line.match(/^-\s*\[\s*\]\s*(.*)$/);
    const checked = line.match(/^-\s*\[[xX]\]\s*(.*)$/);
    if (unchecked) {
      tasks.push({ done: false, text: unchecked[1].trim(), raw: line });
    } else if (checked) {
      tasks.push({ done: true, text: checked[1].trim(), raw: line });
    }
  }
  return tasks;
}

function status() {
  const tasks = getTasks();
  if (tasks.length === 0) {
    console.log('No tasks found in PRD.md. Run init first.');
    return;
  }
  const doneCount = tasks.filter(t => t.done).length;
  console.log(`Ralph Loop Status: ${doneCount}/${tasks.length} tasks completed (${Math.round((doneCount/tasks.length)*100)}%)`);
  tasks.forEach((t, i) => {
    console.log(`  [${t.done ? '✓' : ' '}] ${i + 1}. ${t.text}`);
  });
}

function nextTask() {
  const tasks = getTasks();
  const next = tasks.find(t => !t.done);
  if (!next) {
    console.log('ALL_TASKS_COMPLETED: All tasks in PRD.md have been marked done!');
    return null;
  }
  console.log(`NEXT_TASK: ${next.text}`);
  return next;
}

function completeTask(taskQuery, notes = '') {
  const prdPath = getPRDPath();
  const progPath = getProgressPath();
  if (!fs.existsSync(prdPath)) {
    console.error('PRD.md not found.');
    return;
  }

  let prdContent = fs.readFileSync(prdPath, 'utf8');
  const tasks = getTasks();
  const target = tasks.find(t => !t.done && (t.text.toLowerCase().includes(taskQuery.toLowerCase()) || taskQuery === 'next'));

  if (!target) {
    console.error('No matching uncompleted task found for:', taskQuery);
    return;
  }

  // Mark task done in PRD.md
  const updatedLine = target.raw.replace(/-\s*\[\s*\]/, '- [x]');
  prdContent = prdContent.replace(target.raw, updatedLine);
  fs.writeFileSync(prdPath, prdContent, 'utf8');

  // Append to progress.txt
  const logEntry = `\n[${new Date().toISOString()}] COMPLETED: ${target.text}\nNotes: ${notes || 'Task completed successfully.'}\n----------------------------------------\n`;
  fs.appendFileSync(progPath, logEntry, 'utf8');

  console.log(`✓ Completed: ${target.text}`);
}

const args = process.argv.slice(2);
const command = args[0] || 'status';

switch (command) {
  case 'init':
    init();
    break;
  case 'status':
    status();
    break;
  case 'next':
    nextTask();
    break;
  case 'complete':
    completeTask(args[1] || 'next', args[2] || '');
    break;
  default:
    console.log('Usage: node ralph-loop.js [init|status|next|complete]');
}
