# Calendrier des programmations — plugin Jeedom

Un calendrier de tout ce que Jeedom va exécuter, jour par jour.

## Ce qu'il apporte

- **Scénarios programmés** (mode « Programmé » ou « Les deux »), avec toutes
  leurs programmations, y compris celles calculées par une expression
  (`#[Maison][Soleil][Lever]#`), projetées avec leur valeur actuelle.
- **Blocs A et DANS en attente**, avec leur heure exacte à la seconde.
- **Retours d'état, actions sur valeur et alertes différées** des commandes.
- **Tâches des plugins** inscrites au moteur de tâches, rattachées à leur
  équipement.
- **Fonctions cron des plugins** (`cron`, `cron5`… `cronDaily`) : quels plugins
  tournent, et quand.
- **Tâches de Jeedom** : sauvegarde, maintenance quotidienne, archivage de
  l'historique ; le moteur interne est disponible mais masqué par défaut.
- **Échéances annoncées par les plugins** : commandes `next…` dont la valeur est
  une date, et méthode `calendrierbeEvents()` pour les plugins qui veulent
  exposer leur planning.
- Vues **mois**, **semaine** et **jour**, filtres par catégorie, recherche, et
  lien direct vers le scénario ou l'équipement concerné.

## Ce qu'il ne fait pas

- Il ne modifie rien : ni le coeur, ni les scénarios, ni les tâches.
- Il ne peut pas prévoir ce qui dépend d'un événement (déclencheurs de
  scénarios, listeners), ni les `sleep` et `wait` internes à un scénario, ni les
  planifications qu'un plugin calcule dans son `cron()` sans les exposer.
- Il ne montre pas le passé : ce qui a tourné est dans les journaux.

Documentation complète : [docs/fr_FR/index.md](docs/fr_FR/index.md).
