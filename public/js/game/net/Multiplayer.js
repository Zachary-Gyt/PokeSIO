/**
 * Le MULTIJOUEUR côté navigateur : la connexion à Laravel Reverb (via Laravel Echo).
 *
 *   - canal privé "character.{id}" : mes notifications (invitations, échange, combat, titre perdu) ;
 *   - canal de présence "map.{carte}" : les joueurs sur la même carte que moi, et leurs positions.
 *
 * Les positions passent par des "whispers" : de navigateur à navigateur, sans passer par Laravel
 * (rapide, et ça ne charge pas le serveur). Elles ne servent qu'à l'affichage : tout ce qui compte
 * (échanges, combats) est vérifié par Laravel.
 *
 * Si Reverb n'est pas lancé (ou BROADCAST_CONNECTION n'est pas "reverb"), le jeu marche quand même :
 * on ne voit simplement pas les autres joueurs, et les combats entre joueurs se rafraîchissent
 * en interrogeant le serveur toutes les 3 secondes.
 *
 *   const multi = game.registry.get('multi');
 *   multi.on('invitation', (data) => ...);       // types : voir app/Events/PlayerNotice.php
 *   multi.joinMap('cremieu', { onMove, onLeave }, ['route_1']);   // étape 25 : + les cartes collées
 *   multi.sendPosition({ x, y, facing, mode });
 */
export default class Multiplayer extends Phaser.Events.EventEmitter {
    constructor(config) {
        super();
        this.config = config.multiplayer ?? {};
        this.me = config.character;
        this.echo = null;
        this.channels = new Map();   // étape 25 : un canal par carte suivie (la mienne et les collées)
        this.mapKey = null;
        this.lastPosition = null;
        this.connected = false;

        if (this.config.enabled && window.Echo && window.Pusher && this.config.reverb?.key) {
            this.connect();
        } else {
            console.info('Multijoueur désactivé (Reverb non configuré) : le jeu continue en solo.');
        }
    }

    connect() {
        const r = this.config.reverb;
        const secure = r.scheme === 'https';
        try {
            // echo.iife.js (CDN) expose un module : la classe est dans .default
            const EchoClass = window.Echo.default ?? window.Echo;
            this.echo = new EchoClass({
                broadcaster: 'reverb',
                key: r.key,
                wsHost: r.host || window.location.hostname,
                wsPort: r.port,
                wssPort: r.port,
                forceTLS: secure,
                enabledTransports: ['ws', 'wss'],
                authEndpoint: '/broadcasting/auth',
                auth: { headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } },
            });
        } catch (error) {
            console.warn('Connexion à Reverb impossible :', error);
            return;
        }

        const pusher = this.echo.connector.pusher;
        pusher.connection.bind('state_change', ({ current }) => {
            this.connected = current === 'connected';
            this.emit('connection', this.connected);
        });

        // Mes notifications : un seul événement "notice", le champ "type" dit de quoi il s'agit
        this.echo.private(`character.${this.me.id}`).listen('.notice', (data) => this.emit(data.type, data));
    }

    /**
     * J'arrive sur une carte : je rejoins son canal de présence.
     * Étape 25 : et aussi ceux des cartes COLLÉES autour (neighbors), pour voir les joueurs de l'autre côté
     * de la frontière. Les canaux déjà ouverts sont gardés : passer d'une carte collée à l'autre ne fait
     * pas disparaître les joueurs qu'on voyait.
     * handlers : { onMove(player), onLeave(id, mapKey) }  player = { id, name, gender, avatar, map, x, y, facing, mode }
     * (x, y sont dans les coordonnées de player.map : c'est à la scène de les placer)
     */
    joinMap(mapKey, handlers, neighbors = []) {
        if (!this.echo) return;
        this.channels ??= new Map();
        const wanted = new Set([mapKey, ...neighbors]);
        for (const key of [...this.channels.keys()]) {
            if (!wanted.has(key)) this.leaveChannel(key);
        }
        // Le chat ne vient que de la carte où je suis
        if (this.mapKey && this.mapKey !== mapKey) this.channels.get(this.mapKey)?.channel.stopListening('.chat');
        this.mapKey = mapKey;
        this.handlers = handlers;
        this.startHeartbeat();

        for (const key of wanted) {
            if (this.channels.has(key)) {
                // Canal déjà ouvert : je redemande où sont les présents (la scène vient de redémarrer)
                this.whisperOn(key, 'hello', { id: this.me.id });
                continue;
            }
            const entry = { members: new Map(), channel: null };
            this.channels.set(key, entry);
            entry.channel = this.echo.join(`map.${key}`)
                .here((members) => {
                    for (const m of members) entry.members.set(m.id, m);
                    // Je demande aux présents où ils sont ; ils répondent avec leur position
                    this.whisperOn(key, 'hello', { id: this.me.id });
                    if (key === this.mapKey) this.resendPosition();
                })
                .joining((m) => {
                    entry.members.set(m.id, m);
                    if (key === this.mapKey) this.resendPosition();
                })
                .leaving((m) => {
                    entry.members.delete(m.id);
                    this.handlers?.onLeave?.(m.id, key);
                })
                .listenForWhisper('hello', () => this.resendPosition())
                .listenForWhisper('move', (data) => {
                    // Un joueur parle sur le canal de SA carte : c'est ce canal qui dit qui il est
                    const member = this.member(entry, data.id);
                    if (!member || data.id === this.me.id || data.map !== key) return;
                    this.handlers?.onMove?.({ ...data, name: member.name, gender: member.gender, avatar: member.avatar });
                });
        }
        // Chat : message vérifié par Laravel, envoyé à toute la carte
        this.channels.get(mapKey).channel.stopListening('.chat').listen('.chat', (message) => this.emit('chat', message));
    }

    leaveChannel(key) {
        this.echo?.leave(`map.${key}`);
        this.channels?.delete(key);
    }

    leaveMap() {
        for (const key of [...(this.channels?.keys() ?? [])]) this.leaveChannel(key);
        this.mapKey = null;
        this.handlers = null;
    }

    /** Ma position (envoyée à chaque pas) */
    sendPosition(position) {
        this.lastPosition = { id: this.me.id, map: this.mapKey, ...position };
        this.lastSentAt = Date.now();
        this.whisper('move', this.lastPosition);
    }

    /**
     * Je renvoie où je suis (un joueur vient d'arriver, ou rappel régulier). La scène fournit la position à jour
     * (positionProvider) : le Pokémon qui suit, chargé après mon arrivée, est ainsi bien envoyé.
     */
    resendPosition() {
        const fresh = this.positionProvider?.();
        if (fresh) this.lastPosition = { id: this.me.id, map: this.mapKey, ...fresh };
        if (!this.lastPosition) return;
        this.lastSentAt = Date.now();
        this.whisper('move', { ...this.lastPosition, map: this.mapKey, instant: true });
    }

    /**
     * Rappel régulier (toutes les 10 s, si je n'ai pas bougé) : un message perdu (connexion lente, ordinateur
     * occupé, joueur arrivé pendant un rechargement...) ne laisse personne invisible plus de quelques secondes.
     */
    startHeartbeat() {
        if (this.heartbeat) return;
        this.heartbeat = setInterval(() => {
            if (this.connected && this.mapKey && Date.now() - (this.lastSentAt ?? 0) > 8000) this.resendPosition();
        }, 10000);
    }

    /** Un membre d'un canal de carte (reçu par here/joining, ou retrouvé dans la liste tenue par Pusher) */
    member(entry, id) {
        const known = entry.members.get(id);
        if (known) return known;
        let found = null;
        entry.channel?.subscription?.members?.each?.((m) => {
            if (m.info?.id === id) found = m.info;
        });
        if (found) entry.members.set(id, found);
        return found;
    }

    /** Un message sur le canal de MA carte */
    whisper(event, data) {
        this.whisperOn(this.mapKey, event, data);
    }

    whisperOn(key, event, data) {
        try {
            this.channels?.get(key)?.channel?.whisper(event, data);
        } catch (error) {
            // pas encore connecté : la position sera renvoyée à la connexion
        }
    }
}
