'use strict';
const get = id => document.getElementById(id);
const stages = ['Proposed', 'In review', 'Published'];
const edits = [{live:'€180',draft:'€195'},{live:'7 December',draft:'14 December'}];
const selectors = [...document.querySelectorAll('[data-edit]')];
const modes = [...document.querySelectorAll('[data-view]')];
const hotspots = [...document.querySelectorAll('.hotspot')];
const timeline = [...document.querySelectorAll('.heading li')];
const play = get('play');
const advance = get('advance');
const conflict = get('conflict');
const note = get('note');
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
let stage = 0;
let active = 0;
let view = 'draft';
let stale = false;
let timer;
let frame = 0;

function currentValue(index) {
    return stale && index === 1 ? '9 December' : edits[index].live;
}
function render(motion = false) {
    timeline.forEach((item, index) => {
        if (index === stage) item.setAttribute('aria-current', 'step');
        else item.removeAttribute('aria-current');
    });
    get('badge').textContent = stale ? 'Conflict' : stages[stage];
    selectors.forEach(button => button.setAttribute('aria-pressed', Number(button.dataset.edit) === active));
    modes.forEach(button => {
        button.hidden = stage === 2;
        button.setAttribute('aria-pressed', button.dataset.view === view);
    });
    get('canvas-label').textContent = stage === 2 ? 'Published sample · Revision 22' : 'Sample page preview';
    hotspots.forEach((button, index) => {
        const value = button.querySelector('.value');
        const text = view === 'live' ? currentValue(index) : edits[index].draft;
        if (value.textContent !== text) {
            value.textContent = text;
            if (motion && !reducedMotion.matches) {
                value.animate([{opacity:0,transform:'translateY(7px)'},{opacity:1,transform:'translateY(0)'}],{duration:390,easing:'ease-out'});
            }
        }
    });
    get('before').textContent = currentValue(active);
    get('after').textContent = edits[active].draft;
    get('feedback').hidden = stage !== 1;
    const result = get('result');
    result.hidden = !stale && stage !== 2;
    result.classList.toggle('warning', stale);
    result.textContent = stale ? 'Approval paused. The live deadline changed to 9 December.' : 'Revision 22 is live.' + (note.value.trim() ? ' Review note: ' + note.value.trim() : '');
    advance.textContent = ['Start review','Approve and publish','Restart walkthrough'][stage];
    advance.disabled = stale;
    conflict.hidden = stage !== 1;
    conflict.textContent = stale ? 'Reset conflict simulation' : 'Simulate a newer live edit';
}
function stop() {
    clearInterval(timer);
    timer = undefined;
    play.textContent = '▶ Play walkthrough';
}
function reset() {
    stage = active = frame = 0;
    stale = false;
    view = 'draft';
    note.value = '';
}
selectors.forEach(button => button.addEventListener('click', () => {
    stop();
    active = Number(button.dataset.edit);
    render();
}));
modes.forEach(button => button.addEventListener('click', () => {
    stop();
    view = button.dataset.view;
    render(true);
}));
advance.addEventListener('click', () => {
    stop();
    if (stage === 2) reset();
    else stage++;
    view = 'draft';
    render(true);
});
conflict.addEventListener('click', () => {
    stop();
    stale = !stale;
    active = 1;
    view = stale ? 'live' : 'draft';
    render(true);
});
play.addEventListener('click', () => {
    if (timer !== undefined) { stop(); return; }
    reset();
    view = 'live';
    render(true);
    play.textContent = 'Ⅱ Pause walkthrough';
    timer = setInterval(() => {
        frame++;
        if (frame === 1) { stage = 1; view = 'draft'; }
        if (frame === 2) active = 1;
        if (frame === 3) { stage = 2; stop(); }
        render(true);
    }, 1900);
});
