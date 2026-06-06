import { createRouter, createWebHistory } from 'vue-router';
import HomeView from './views/HomeView.vue';
import BattleView from './views/BattleView.vue';
import AdminView from './views/AdminView.vue';
import HubView from './views/HubView.vue';
import DungeonView from './views/DungeonView.vue';
import StatusView from './views/StatusView.vue';
import ShopView from './views/ShopView.vue';
import EquipView from './views/EquipView.vue';
import ItemsView from './views/ItemsView.vue';
import { usePlayerStore } from './stores/player';

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
      meta: { title: 'やみまほ' },
    },
    {
      path: '/hub',
      name: 'hub',
      component: HubView,
      meta: { title: '待機' },
    },
    {
      path: '/shop',
      name: 'shop',
      component: ShopView,
      meta: { title: '武具屋' },
    },
    {
      path: '/equip',
      name: 'equip',
      component: EquipView,
      meta: { title: '装備' },
    },
    {
      path: '/items',
      name: 'items',
      component: ItemsView,
      meta: { title: '持ち物' },
    },
    {
      path: '/dungeon',
      name: 'dungeon',
      component: DungeonView,
      meta: { title: 'ダンジョン' },
    },
    {
      path: '/status',
      name: 'status',
      component: StatusView,
      meta: { title: 'ステータス' },
    },
    {
      path: '/battle/:id?',
      name: 'battle',
      component: BattleView,
      props: true,
      meta: { title: '戦闘' },
    },
    {
      path: '/admins',
      name: 'admin',
      component: AdminView,
      meta: { title: '管理' },
    },
  ],
});

router.beforeEach(async (to) => {
  const player = usePlayerStore();

  try {
    await player.fetchNavigation();
  } catch {
    return true;
  }

  const hubBlocked = ['hub', 'shop', 'equip', 'items'];
  if (player.inDungeon && hubBlocked.includes(String(to.name))) {
    return { name: 'dungeon', replace: true };
  }

  if (player.activeBattleId) {
    if (['hub', 'dungeon', 'shop', 'equip', 'items'].includes(String(to.name))) {
      return {
        name: 'battle',
        params: { id: player.activeBattleId },
        query: player.battleFromDungeon ? { from: 'dungeon' } : {},
        replace: true,
      };
    }
  }

  if (to.name === 'dungeon' && !player.inDungeon && !player.activeBattleId) {
    return { name: 'hub', replace: true };
  }

  return true;
});

export default router;
