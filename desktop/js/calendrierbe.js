/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Jeedom recharge ce script à chaque visite de la page, sans recharger la
   fenêtre : tout l'état tient dans des « var », qu'une seconde déclaration
   remplace sans erreur, là où un « let » global ferait échouer le script. */

/* ================================================================== ÉTAT */

var calendrierbeState = {
  view: 'month',
  anchor: null,     /* le jour de référence de la vue, à minuit */
  selected: null,   /* la journée détaillée, « AAAA-MM-JJ » */
  filters: {},      /* catégorie => affichée ou non */
  search: '',
  data: null,       /* dernière réponse du serveur */
  error: ''         /* dernier échec de chargement, affiché à la place de la grille */
}

var calendrierbeRoot = document.getElementById('div_calendrierbe')

/* La requête en cours. Gardée sur window et non dans l'état : une visite
   précédente de la page a pu laisser la sienne en vol, et c'est elle qu'il
   faut annuler pour que sa réponse ne vienne pas s'afficher ici. */
if (window.calendrierbeAbort) {
  window.calendrierbeAbort.abort()
}
window.calendrierbeAbort = null

/* Le nombre de lignes montrées dans une case du mois avant « + n autres ». */
var calendrierbeCellLines = 4

/* Au-delà, la page dit que le calcul n'a pas abouti plutôt que de tourner
   indéfiniment. */
var calendrierbeTimeout = 60000

/* ================================================================ OUTILS */

function calendrierbeEscape(_text) {
  return String(_text === null || _text === undefined ? '' : _text)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
}

/* Pour chercher sans se soucier des accents ni des majuscules. */
function calendrierbeFold(_text) {
  return String(_text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

function calendrierbeCapitalize(_text) {
  return _text.charAt(0).toUpperCase() + _text.slice(1)
}

function calendrierbeKey(_date) {
  var m = _date.getMonth() + 1
  var d = _date.getDate()
  return _date.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d
}

function calendrierbeParseKey(_key) {
  var p = _key.split('-')
  return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10))
}

function calendrierbeMidnight(_date) {
  return new Date(_date.getFullYear(), _date.getMonth(), _date.getDate())
}

/* Ajouter des jours par le calendrier, pas par 86 400 secondes : les jours
   de changement d'heure en ont 23 ou 25. */
function calendrierbeAddDays(_date, _days) {
  return new Date(_date.getFullYear(), _date.getMonth(), _date.getDate() + _days)
}

/* Le lundi de la semaine du jour donné. */
function calendrierbeMonday(_date) {
  var shift = (_date.getDay() + 6) % 7
  return calendrierbeAddDays(calendrierbeMidnight(_date), -shift)
}

function calendrierbeFormat(_date, _options) {
  return _date.toLocaleDateString('fr-FR', _options)
}

function calendrierbeToday() {
  return calendrierbeKey(new Date())
}

function calendrierbeStore(_key, _value) {
  try {
    localStorage.setItem('calendrierbe.' + _key, JSON.stringify(_value))
  } catch (e) { }
}

function calendrierbeRestore(_key, _default) {
  try {
    var value = localStorage.getItem('calendrierbe.' + _key)
    return value === null ? _default : JSON.parse(value)
  } catch (e) {
    return _default
  }
}

/* Un lien vers une page de Jeedom ou une adresse web, rien d'autre. Le
   serveur le vérifie déjà ; le refaire ici ne coûte rien. */
function calendrierbeSafeLink(_link) {
  return /^(index\.php\?|https?:\/\/)/i.test(_link || '') ? _link : ''
}

/* ========================================================== LA PÉRIODE */

/* Les jours affichés par la vue courante : [premier, dernier + 1). */
function calendrierbeRange() {
  var anchor = calendrierbeState.anchor
  if (calendrierbeState.view == 'day') {
    return { start: anchor, end: calendrierbeAddDays(anchor, 1) }
  }
  if (calendrierbeState.view == 'week') {
    var monday = calendrierbeMonday(anchor)
    return { start: monday, end: calendrierbeAddDays(monday, 7) }
  }
  /* Le mois s'affiche sur six semaines complètes, du lundi au dimanche. */
  var first = new Date(anchor.getFullYear(), anchor.getMonth(), 1)
  var start = calendrierbeMonday(first)
  return { start: start, end: calendrierbeAddDays(start, 42) }
}

/*
 * Le jour détaillé suit la navigation. Resté sur le 25 septembre pendant
 * qu'on affiche octobre, il ramènerait en septembre au prochain changement de
 * vue, et le panneau décrirait une journée absente de la grille. On garde le
 * jour choisi s'il est encore visible ; sinon, aujourd'hui s'il l'est, et à
 * défaut le jour de référence de la vue.
 */
function calendrierbeSyncSelected() {
  if (calendrierbeState.view == 'day') {
    calendrierbeState.selected = calendrierbeKey(calendrierbeState.anchor)
    return
  }
  var range = calendrierbeRange()
  var first = calendrierbeKey(range.start)
  var last = calendrierbeKey(calendrierbeAddDays(range.end, -1))
  var inside = function (_key) {
    return _key && _key >= first && _key <= last
  }
  if (calendrierbeState.view == 'month') {
    /* En vue mois, les jours des mois voisins en bordure ne comptent pas. */
    var month = calendrierbeState.anchor.getMonth()
    inside = function (_key) {
      return _key && calendrierbeParseKey(_key).getMonth() == month &&
        calendrierbeParseKey(_key).getFullYear() == calendrierbeState.anchor.getFullYear()
    }
  }
  if (inside(calendrierbeState.selected)) {
    return
  }
  calendrierbeState.selected = inside(calendrierbeToday()) ? calendrierbeToday() : calendrierbeKey(calendrierbeState.anchor)
}

function calendrierbeTitle() {
  var anchor = calendrierbeState.anchor
  if (calendrierbeState.view == 'day') {
    return calendrierbeCapitalize(calendrierbeFormat(anchor, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))
  }
  if (calendrierbeState.view == 'week') {
    var range = calendrierbeRange()
    var last = calendrierbeAddDays(range.end, -1)
    return '{{Semaine du}} ' + calendrierbeFormat(range.start, { day: 'numeric', month: 'long' }) +
      ' {{au}} ' + calendrierbeFormat(last, { day: 'numeric', month: 'long', year: 'numeric' })
  }
  return calendrierbeCapitalize(calendrierbeFormat(anchor, { month: 'long', year: 'numeric' }))
}

/* ========================================================= LES DONNÉES */

function calendrierbeSetLoading(_loading) {
  calendrierbeRoot.classList.toggle('calbe-loading', _loading)
}

function calendrierbeLoad() {
  calendrierbeSyncSelected()
  var range = calendrierbeRange()
  var from = Math.floor(range.start.getTime() / 1000)
  var to = Math.floor(range.end.getTime() / 1000) - 1
  calendrierbeRoot.querySelector('.calbe-title').textContent = calendrierbeTitle()
  if (window.calendrierbeAbort) {
    window.calendrierbeAbort.abort()
    window.calendrierbeAbort = null
  }
  calendrierbeState.error = ''

  /* Une période entièrement passée : rien à demander, un calendrier de
     prévisions n'a rien à y montrer. */
  if (to < Date.now() / 1000) {
    calendrierbeSetLoading(false)
    calendrierbeState.data = { items: [], warnings: [], now: Math.floor(Date.now() / 1000) }
    calendrierbeRender()
    return
  }

  /*
   * fetch plutôt que domUtils.ajax : ce dernier ne prévient pas l'appelant
   * d'une erreur HTTP (500, 504) et relance trois fois la requête. Le calcul
   * reviendrait quatre fois de suite, et la page resterait sans réponse.
   */
  var controller = new AbortController()
  window.calendrierbeAbort = controller
  var root = calendrierbeRoot
  var timer = setTimeout(function () {
    controller.abort('timeout')
  }, calendrierbeTimeout)
  calendrierbeSetLoading(true)
  var body = new URLSearchParams({ action: 'events', from: from, to: to })
  fetch('plugins/calendrierbe/core/ajax/calendrierbe.ajax.php', {
    method: 'POST',
    body: body,
    credentials: 'same-origin',
    signal: controller.signal
  }).then(function (response) {
    if (!response.ok) {
      throw new Error('{{Erreur HTTP}} ' + response.status + ' ' + response.statusText)
    }
    return response.json().catch(function () {
      throw new Error('{{Réponse illisible du serveur (voir le journal http.error)}}')
    })
  }).then(function (data) {
    if (data.state != 'ok') {
      throw new Error(data.result)
    }
    /* Le serveur envoie chaque tâche une fois et ses exécutions à part :
       on les réunit ici, pour que l'affichage n'ait qu'un objet à lire. */
    var tasks = data.result.tasks || {}
    data.result.items = data.result.items.map(function (item) {
      return Object.assign({ count: 1 }, tasks[item.task] || {}, item)
    }).sort(function (a, b) {
      return a.ts - b.ts
    })
    return data.result
  }).then(function (result) {
    calendrierbeFinish(controller, root, timer, result, '')
  }).catch(function (error) {
    var message = (controller.signal.reason === 'timeout') ?
      '{{Le calcul du calendrier n\'a pas abouti dans le temps imparti.}}' :
      (error && error.message ? error.message : String(error))
    calendrierbeFinish(controller, root, timer, null, message)
  })
}

/* La fin d'une requête, réussie ou non : elle ne s'affiche que si elle est
   encore la dernière demandée, sur la page encore affichée. */
function calendrierbeFinish(_controller, _root, _timer, _result, _error) {
  clearTimeout(_timer)
  if (window.calendrierbeAbort !== _controller || _root !== calendrierbeRoot) {
    return
  }
  window.calendrierbeAbort = null
  calendrierbeSetLoading(false)
  if (_error !== '') {
    /* Plus de données plutôt que celles de la période précédente sous le
       titre de la nouvelle. */
    calendrierbeState.data = null
    calendrierbeState.error = _error
  } else {
    calendrierbeState.data = _result
    calendrierbeState.error = ''
  }
  calendrierbeRender()
}

/* Une ligne répond-elle à la recherche ? */
function calendrierbeMatches(_item, _search) {
  if (_search === '') {
    return true
  }
  var category = calendrierbeCategories[_item.category] || {}
  var text = [_item.title, _item.detail, _item.frequency, category.label].join(' ')
  return calendrierbeFold(text).indexOf(_search) >= 0
}

/* Les éléments affichables, filtrés par catégorie et par recherche, rangés
   par jour. */
function calendrierbeByDay() {
  var byDay = {}
  if (!calendrierbeState.data) {
    return byDay
  }
  var search = calendrierbeFold(calendrierbeState.search)
  calendrierbeState.data.items.forEach(function (item) {
    if (!calendrierbeState.filters[item.category] || !calendrierbeMatches(item, search)) {
      return
    }
    if (!byDay[item.date]) {
      byDay[item.date] = []
    }
    byDay[item.date].push(item)
  })
  return byDay
}

/* ======================================================= PICS DE CHARGE */

/*
 * Les minutes où partent ensemble au moins calendrierbePeakThreshold lignes
 * affichées : jeeCron les lance en même temps, et minuit en accumule souvent
 * (fonctions cronDaily des plugins, maintenance, scénarios quotidiens). Une
 * tâche résumée compte à chacune des heures de sa liste ; celle qui part plus
 * de 48 fois par jour n'a pas de liste, et n'est pas comptée : elle tombe sur
 * chaque pic, sans rien apprendre de plus. Une tâche en retard ou échouée est
 * montrée à l'heure qu'il est, pas à celle où elle partira : elle n'y compte
 * pas non plus.
 */
function calendrierbePeaks(_items) {
  var threshold = parseInt(calendrierbePeakThreshold, 10) || 0
  if (threshold < 2) {
    return []
  }
  var byMinute = {}
  _items.forEach(function (item) {
    if (item.allDay || item.late || item.failed) {
      return
    }
    var times = (item.count > 1) ? (item.times || []) : [item.time]
    times.forEach(function (time) {
      if (!byMinute[time]) {
        byMinute[time] = []
      }
      byMinute[time].push(item)
    })
  })
  return Object.keys(byMinute).sort().filter(function (time) {
    return byMinute[time].length >= threshold
  }).map(function (time) {
    return { time: time, items: byMinute[time] }
  })
}

/* La pastille d'une case du mois ou d'une colonne de la semaine : le plus
   gros pic du jour, et tous les pics dans l'infobulle. */
function calendrierbePeakBadge(_items) {
  var peaks = calendrierbePeaks(_items)
  if (peaks.length == 0) {
    return ''
  }
  var max = 0
  var list = peaks.map(function (peak) {
    max = Math.max(max, peak.items.length)
    return peak.items.length + ' {{à}} ' + peak.time
  })
  return '<span class="calbe-peak" title="' + calendrierbeEscape('{{Pic de charge : tâches qui partent à la même minute}} — ' + list.join(', ')) + '">' +
    '<i class="fas fa-layer-group"></i> ' + max + '</span>'
}

function calendrierbePeakRowHtml(_peak) {
  var titles = _peak.items.map(function (item) {
    return calendrierbeEscape(item.title)
  })
  return '<div class="calbe-peak-row" title="{{Décaler l\'une de ces programmations de quelques minutes étale la charge.}}">' +
    '<i class="fas fa-layer-group"></i> <strong>{{Pic de charge}} · ' + _peak.time + '</strong> — ' +
    _peak.items.length + ' {{tâches partent à la même minute}} : ' + titles.join(', ') + '</div>'
}

/* ========================================================== L'AFFICHAGE */

function calendrierbeRender() {
  calendrierbeRoot.querySelector('.calbe-title').textContent = calendrierbeTitle()
  calendrierbeRoot.querySelectorAll('.calbe-view').forEach(function (button) {
    var active = button.dataset.view == calendrierbeState.view
    button.classList.toggle('active', active)
    button.setAttribute('aria-pressed', active ? 'true' : 'false')
  })
  calendrierbeRenderFilters()
  calendrierbeRenderWarnings()
  var main = calendrierbeRoot.querySelector('.calbe-main')
  var side = calendrierbeRoot.querySelector('.calbe-side')
  if (calendrierbeState.error !== '') {
    main.innerHTML = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> {{Le calendrier n\'a pas pu être calculé :}} ' +
      calendrierbeEscape(calendrierbeState.error) + '</div>'
    side.innerHTML = ''
    side.style.display = 'none'
    return
  }
  var byDay = calendrierbeByDay()
  if (calendrierbeState.view == 'day') {
    var key = calendrierbeKey(calendrierbeState.anchor)
    main.innerHTML = calendrierbeDayHtml(key, byDay[key] || [], true)
    side.innerHTML = ''
    side.style.display = 'none'
    return
  }
  side.style.display = ''
  main.innerHTML = (calendrierbeState.view == 'week') ? calendrierbeWeekHtml(byDay) : calendrierbeMonthHtml(byDay)
  var selected = calendrierbeState.selected
  side.innerHTML = selected ? calendrierbeDayHtml(selected, byDay[selected] || [], false) : ''
}

/* Les pastilles comptent des lignes, comme les cases : une tâche résumée en
   « ×288 » compte pour une, et la recherche est prise en compte. */
function calendrierbeRenderFilters() {
  var counts = {}
  if (calendrierbeState.data) {
    var search = calendrierbeFold(calendrierbeState.search)
    calendrierbeState.data.items.forEach(function (item) {
      if (calendrierbeMatches(item, search)) {
        counts[item.category] = (counts[item.category] || 0) + 1
      }
    })
  }
  var html = ''
  Object.keys(calendrierbeCategories).forEach(function (key) {
    var category = calendrierbeCategories[key]
    var on = !!calendrierbeState.filters[key]
    html += '<a class="calbe-chip calbe-cat-' + key + (on ? ' active' : '') + '" data-category="' + key + '"' +
      ' role="button" tabindex="0" aria-pressed="' + (on ? 'true' : 'false') + '"' +
      ' title="' + (on ? '{{Cliquer pour masquer}}' : '{{Cliquer pour afficher}}') + '">' +
      '<i class="' + category.icon + '"></i> ' + calendrierbeEscape(category.label) +
      ' <span class="calbe-chip-count">' + (counts[key] || 0) + '</span></a>'
  })
  calendrierbeRoot.querySelector('.calbe-filters').innerHTML = html
}

function calendrierbeRenderWarnings() {
  var warnings = ((calendrierbeState.data && calendrierbeState.data.warnings) || []).slice()
  /* Les dates et les heures viennent de Jeedom ; la grille, du navigateur.
     Dans deux fuseaux différents, un événement proche de minuit changerait de
     jour : on le dit plutôt que de laisser croire à une erreur d'heure. */
  var serverZone = calendrierbeState.data && calendrierbeState.data.timezone
  var browserZone = ''
  try {
    browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone
  } catch (e) { }
  if (serverZone && browserZone && serverZone != browserZone) {
    warnings.push('{{Heures de Jeedom}} (' + serverZone + ') {{et non de ce navigateur}} (' + browserZone + ') : {{les jours affichés peuvent être décalés près de minuit.}}')
  }
  calendrierbeRoot.querySelector('.calbe-warnings').innerHTML = warnings.map(function (warning) {
    return '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> ' + calendrierbeEscape(warning) + '</div>'
  }).join('')
}

/* Une ligne compacte, pour les cases du mois et les colonnes de la semaine. */
function calendrierbeLineHtml(_item) {
  var category = calendrierbeCategories[_item.category] || { icon: 'fas fa-circle' }
  var time = _item.allDay ? '' : _item.time
  var count = (_item.count > 1) ? ' <span class="calbe-times">×' + _item.count + '</span>' : ''
  var flags = (_item.late || _item.failed) ? ' calbe-late' : ''
  flags += _item.disabled ? ' calbe-disabled' : ''
  flags += _item.dynamic ? ' calbe-dynamic' : ''
  return '<div class="calbe-line calbe-cat-' + _item.category + flags + '" title="' + calendrierbeEscape(_item.title + (_item.detail ? '\n' + _item.detail : '')) + '">' +
    '<i class="' + category.icon + '"></i> <span class="calbe-line-time">' + time + '</span> ' +
    calendrierbeEscape(_item.title) + count + '</div>'
}

/* L'ordre des lignes dans une case du mois ou une colonne de la semaine, où la
   place manque. D'abord ce que l'utilisateur a programmé lui-même (scénarios,
   blocs, commandes, plugins, échéances), puis les tâches de Jeedom qui partent
   une fois, et enfin les résumés des tâches répétées ; dans l'ordre de l'heure
   à l'intérieur de chaque rang. Dans l'ordre de l'heure seul, les fonctions
   cron des plugins, qui partent toutes à minuit, occuperaient chaque case et
   cacheraient sous « + n autres » le scénario qu'on cherche. */
var calendrierbeBackground = ['systeme', 'fonction', 'moteur']

function calendrierbeRank(_item) {
  if (_item.count > 1) {
    return 2
  }
  return (calendrierbeBackground.indexOf(_item.category) >= 0) ? 1 : 0
}

function calendrierbeCompactOrder(_items) {
  return _items.slice().sort(function (a, b) {
    return (calendrierbeRank(a) - calendrierbeRank(b)) || (a.ts - b.ts)
  })
}

/* L'attribut d'accessibilité d'un élément cliquable qui n'est pas un bouton. */
function calendrierbeButtonAttrs(_label) {
  return ' role="button" tabindex="0" aria-label="' + calendrierbeEscape(_label) + '"'
}

function calendrierbeMonthHtml(_byDay) {
  var range = calendrierbeRange()
  var month = calendrierbeState.anchor.getMonth()
  var today = calendrierbeToday()
  var html = '<div class="calbe-month"><div class="calbe-weekdays">'
  var names = ['{{Lun}}', '{{Mar}}', '{{Mer}}', '{{Jeu}}', '{{Ven}}', '{{Sam}}', '{{Dim}}']
  names.forEach(function (name) {
    html += '<div>' + name + '</div>'
  })
  html += '</div><div class="calbe-grid">'
  for (var i = 0; i < 42; i++) {
    var day = calendrierbeAddDays(range.start, i)
    var key = calendrierbeKey(day)
    var items = _byDay[key] || []
    var classes = 'calbe-cell'
    classes += (day.getMonth() != month) ? ' calbe-other' : ''
    classes += (key < today) ? ' calbe-past' : ''
    classes += (key == today) ? ' calbe-today' : ''
    classes += (key == calendrierbeState.selected) ? ' calbe-selected' : ''
    html += '<div class="' + classes + '" data-day="' + key + '"' +
      calendrierbeButtonAttrs(calendrierbeFormat(day, { weekday: 'long', day: 'numeric', month: 'long' }) + ' — ' + items.length + ' {{ligne(s)}}') + '>' +
      '<div class="calbe-cell-head"><span class="calbe-daynum">' + day.getDate() + '</span><span class="calbe-head-badges">'
    if (items.length > 0) {
      html += '<span class="calbe-badge" title="{{Lignes affichées ce jour-là}}">' + items.length + '</span>'
    }
    html += calendrierbePeakBadge(items) + '</span></div>'
    calendrierbeCompactOrder(items).slice(0, calendrierbeCellLines).forEach(function (item) {
      html += calendrierbeLineHtml(item)
    })
    if (items.length > calendrierbeCellLines) {
      html += '<div class="calbe-more">+ ' + (items.length - calendrierbeCellLines) + ' {{autre(s)}}</div>'
    }
    html += '</div>'
  }
  return html + '</div></div>'
}

function calendrierbeWeekHtml(_byDay) {
  var range = calendrierbeRange()
  var today = calendrierbeToday()
  var html = '<div class="calbe-week">'
  for (var i = 0; i < 7; i++) {
    var day = calendrierbeAddDays(range.start, i)
    var key = calendrierbeKey(day)
    var items = _byDay[key] || []
    var classes = 'calbe-weekday'
    classes += (key < today) ? ' calbe-past' : ''
    classes += (key == today) ? ' calbe-today' : ''
    classes += (key == calendrierbeState.selected) ? ' calbe-selected' : ''
    var label = calendrierbeFormat(day, { weekday: 'short', day: 'numeric', month: 'short' })
    html += '<div class="' + classes + '" data-day="' + key + '"' + calendrierbeButtonAttrs(label) + '>' +
      '<div class="calbe-weekday-head">' + calendrierbeEscape(label) + '<span class="calbe-head-badges">' +
      (items.length ? '<span class="calbe-badge" title="{{Lignes affichées ce jour-là}}">' + items.length + '</span>' : '') +
      calendrierbePeakBadge(items) + '</span></div>'
    calendrierbeCompactOrder(items).forEach(function (item) {
      html += calendrierbeLineHtml(item)
    })
    html += '</div>'
  }
  return html + '</div>'
}

/* Le nombre d'exécutions d'une journée : une ligne résumée en vaut plusieurs. */
function calendrierbeTotal(_items) {
  return _items.reduce(function (total, item) {
    return total + (item.count || 1)
  }, 0)
}

/* Le détail d'une journée : chaque déclenchement, heure par heure. Ce qui
   n'a pas d'heure (une échéance « dans la journée ») vient en tête. */
function calendrierbeDayHtml(_key, _items, _large) {
  var day = calendrierbeParseKey(_key)
  var title = calendrierbeCapitalize(calendrierbeFormat(day, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))
  var html = '<div class="calbe-day' + (_large ? ' calbe-day-large' : '') + '"><div class="calbe-day-head">' +
    '<span>' + calendrierbeEscape(title) + '</span>'
  if (!_large) {
    html += '<a class="btn btn-xs btn-default calbe-open-day" data-day="' + _key + '" title="{{Vue du jour}}"><i class="fas fa-expand"></i></a>'
  }
  html += '</div>'
  if (_key < calendrierbeToday()) {
    return html + '<div class="calbe-empty">{{Journée passée : ce calendrier ne montre que ce qui va se produire.}}</div></div>'
  }
  if (!calendrierbeState.data) {
    return html + '<div class="calbe-empty">{{Chargement…}}</div></div>'
  }
  if (_items.length == 0) {
    return html + '<div class="calbe-empty">{{Rien de programmé ce jour-là pour les catégories affichées.}}</div></div>'
  }
  var total = calendrierbeTotal(_items)
  var peaks = calendrierbePeaks(_items)
  html += '<div class="calbe-day-summary">' + _items.length + ' {{ligne(s)}}' +
    (total > _items.length ? ', ' + total + ' {{exécutions en tout}}' : '') +
    (peaks.length ? ', <span class="calbe-peak-text">' + peaks.length + ' {{pic(s) de charge}}</span>' : '') + '</div>'
  var ordered = _items.slice().sort(function (a, b) {
    return ((b.allDay ? 1 : 0) - (a.allDay ? 1 : 0)) || (a.ts - b.ts)
  })
  /* Chaque pic prend place dans le fil de la journée, juste avant la première
     ligne de sa minute, sous l'en-tête de son heure. */
  var hour = null
  var heading = function (_hour) {
    if (_hour !== hour) {
      hour = _hour
      html += '<div class="calbe-hour">' + hour + '</div>'
    }
  }
  var flushPeaks = function (_until) {
    while (peaks.length && (_until === null || peaks[0].time <= _until)) {
      heading(peaks[0].time.substr(0, 2) + ' h')
      html += calendrierbePeakRowHtml(peaks.shift())
    }
  }
  ordered.forEach(function (item) {
    if (!item.allDay) {
      flushPeaks(item.time)
    }
    heading(item.allDay ? '{{Dans la journée}}' : item.time.substr(0, 2) + ' h')
    html += calendrierbeEntryHtml(item)
  })
  flushPeaks(null)
  return html + '</div>'
}

function calendrierbeEntryHtml(_item) {
  var category = calendrierbeCategories[_item.category] || { icon: 'fas fa-circle', label: _item.category }
  var title = calendrierbeEscape(_item.title)
  var link = calendrierbeSafeLink(_item.link)
  if (link) {
    title = '<a href="' + calendrierbeEscape(link) + '">' + title + '</a>'
  }
  var time = _item.allDay ? '—' : _item.time
  var badges = ''
  if (_item.count > 1) {
    time = _item.time + '<br><small>' + calendrierbeEscape(_item.last) + '</small>'
    badges += '<span class="label label-info">×' + _item.count + (_item.frequency ? ' · ' + calendrierbeEscape(_item.frequency) : '') + '</span> '
  }
  if (_item.once) {
    badges += '<span class="label label-default">{{ponctuelle}}</span> '
  }
  if (_item.late) {
    badges += '<span class="label label-danger" title="{{L\'heure prévue est passée sans que la tâche parte : Jeedom ne rattrape pas les tâches manquées, elle attendra l\'an prochain.}}">{{en retard}} · {{prévue le}} ' + calendrierbeEscape(_item.planned) + '</span> '
  }
  if (_item.failed) {
    badges += '<span class="label label-danger" title="{{La tâche est partie mais a échoué ; elle reste dans le moteur de tâches.}}">{{échouée}} · {{le}} ' + calendrierbeEscape(_item.planned) + '</span> '
  }
  if (_item.dynamic) {
    badges += '<span class="label label-warning" title="{{Heure calculée par une expression : elle peut changer d\'ici là.}}">{{estimation}}</span> '
  }
  if (_item.disabled) {
    badges += '<span class="label label-danger" title="{{Cette exécution n\'aura pas lieu tant que la cause n\'est pas levée.}}">{{ne partira pas}} : ' + calendrierbeEscape(_item.disabled) + '</span> '
  }
  var html = '<div class="calbe-entry calbe-cat-' + _item.category + ((_item.late || _item.failed) ? ' calbe-late' : '') + '">' +
    '<div class="calbe-entry-time">' + time + '</div>' +
    '<div class="calbe-entry-icon" title="' + calendrierbeEscape(category.label) + '"><i class="' + category.icon + '"></i></div>' +
    '<div class="calbe-entry-body"><div class="calbe-entry-title">' + title + ' ' + badges + '</div>'
  if (_item.detail) {
    html += '<div class="calbe-entry-detail">' + calendrierbeEscape(_item.detail) + '</div>'
  }
  if (_item.times && _item.times.length) {
    html += '<details class="calbe-entry-times"><summary>{{Voir les heures}}</summary>' + calendrierbeEscape(_item.times.join(' · ')) + '</details>'
  }
  return html + '</div></div>'
}

/* ============================================================ ÉVÉNEMENTS */

function calendrierbeSelectDay(_key) {
  calendrierbeState.selected = _key
  calendrierbeRender()
  /* Sous 1100 px, le détail passe sous la grille : sans ce défilement, un
     clic sur une case semblerait ne rien faire. */
  var side = calendrierbeRoot.querySelector('.calbe-side')
  if (side && side.offsetTop > calendrierbeRoot.querySelector('.calbe-main').offsetTop + 50) {
    side.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
}

/* Les écouteurs sont posés sur la racine de la page, une fois par chargement
   du script : la page est vidée et réinjectée par Jeedom à chaque visite, avec
   un nouvel élément racine, et rien ne reste accroché à l'ancien. */
calendrierbeRoot.addEventListener('click', function (event) {
  var target
  if ((target = event.target.closest('.calbe-nav'))) {
    var step = (target.dataset.nav == 'prev') ? -1 : 1
    var anchor = calendrierbeState.anchor
    if (target.dataset.nav == 'today') {
      calendrierbeState.anchor = calendrierbeMidnight(new Date())
      calendrierbeState.selected = calendrierbeToday()
    } else if (calendrierbeState.view == 'month') {
      calendrierbeState.anchor = new Date(anchor.getFullYear(), anchor.getMonth() + step, 1)
    } else {
      calendrierbeState.anchor = calendrierbeAddDays(anchor, step * (calendrierbeState.view == 'week' ? 7 : 1))
    }
    calendrierbeLoad()
    return
  }
  if ((target = event.target.closest('.calbe-view'))) {
    if (target.dataset.view == calendrierbeState.view) {
      return
    }
    calendrierbeState.view = target.dataset.view
    if (calendrierbeState.selected) {
      calendrierbeState.anchor = calendrierbeParseKey(calendrierbeState.selected)
    }
    calendrierbeStore('view', calendrierbeState.view)
    calendrierbeLoad()
    return
  }
  if ((target = event.target.closest('.calbe-open-day'))) {
    calendrierbeState.view = 'day'
    calendrierbeState.anchor = calendrierbeParseKey(target.dataset.day)
    calendrierbeState.selected = target.dataset.day
    calendrierbeStore('view', calendrierbeState.view)
    calendrierbeLoad()
    return
  }
  if ((target = event.target.closest('.calbe-chip'))) {
    var key = target.dataset.category
    calendrierbeState.filters[key] = !calendrierbeState.filters[key]
    calendrierbeStore('filters', calendrierbeState.filters)
    calendrierbeRender()
    return
  }
  if (event.target.closest('.calbe-refresh')) {
    calendrierbeLoad()
    return
  }
  if (event.target.closest('.calbe-config')) {
    jeedomUtils.loadPage('index.php?v=d&p=plugin&id=calendrierbe')
    return
  }
  /* Un clic sur une journée la détaille, sauf s'il visait un lien ou le
     dépliage des heures. */
  if (!event.target.closest('a, summary, details') && (target = event.target.closest('[data-day]'))) {
    calendrierbeSelectDay(target.dataset.day)
  }
})

/* Au clavier, Entrée et Espace valent un clic sur ce qui se comporte comme un
   bouton sans en être un. */
calendrierbeRoot.addEventListener('keydown', function (event) {
  if (event.key != 'Enter' && event.key != ' ') {
    return
  }
  var target = event.target.closest('[role="button"]')
  if (!target || target.tagName == 'BUTTON') {
    return
  }
  event.preventDefault()
  target.click()
})

calendrierbeRoot.querySelector('.calbe-search').addEventListener('input', function (event) {
  calendrierbeState.search = event.target.value.trim()
  calendrierbeRender()
})

/* ============================================================ DÉMARRAGE */

;(function () {
  var stored = calendrierbeRestore('filters', {})
  if (!stored || typeof stored != 'object') {
    stored = {}
  }
  Object.keys(calendrierbeCategories).forEach(function (key) {
    calendrierbeState.filters[key] = (stored[key] !== undefined) ? !!stored[key] : calendrierbeCategories[key].on == 1
  })
  var view = calendrierbeRestore('view', 'month')
  calendrierbeState.view = (['month', 'week', 'day'].indexOf(view) >= 0) ? view : 'month'
  calendrierbeState.anchor = calendrierbeMidnight(new Date())
  calendrierbeState.selected = calendrierbeToday()
  calendrierbeLoad()
})()
