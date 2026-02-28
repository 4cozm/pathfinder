/**
 * context menu
 */

define([
    'jquery',
    'app/render'
], ($, Render) => {

    'use strict';

    let config = {
        contextMenuContainerId: 'pf-contextmenu-container',                         // id for container element that holds (hidden) context menus
        mapContextMenuId: 'pf-map-contextmenu',                                     // id for "maps" context menu
        connectionContextMenuId: 'pf-map-connection-contextmenu',                   // id for "connections" context menu
        endpointContextMenuId: 'pf-map-endpoint-contextmenu',                       // id for "endpoints" context menu
        systemContextMenuId: 'pf-map-system-contextmenu',                           // id for "systems" context menu

        contextMenuClass: 'dropdown-menu',                                          // class for all context menus
        subMenuLeftClass: 'dropdown-submenu-left',                                  // class moves submenus to the left side

        animationInType: 'transition.flipXIn',
        animationInDuration: 150,
        animationOutType: 'transition.flipXOut',
        animationOutDuration: 150
    };

    /**
     * calc menu X coordinate
     * @param e
     * @param menuWidth
     * @returns {number|*}
     */
    let getMenuLeftCoordinate = (e, menuWidth) => {
        let mouseWidth = e.pageX;
        let openSubLeft = false;
        if(mouseWidth + menuWidth > window.innerWidth && menuWidth < mouseWidth){
            // opening menu would pass the side of the page
            openSubLeft = true;
            //return mouseWidth - menuWidth;
            mouseWidth -= menuWidth;
        }else if(mouseWidth + menuWidth * 2 > window.innerWidth && menuWidth * 2 < mouseWidth){
            // opening submenu would pass the side of the page
            openSubLeft = true;
        }
        return {
            left: mouseWidth,
            openSubLeft: openSubLeft
        };
    };

    /**
     * calc menu Y coordinate
     * @param e
     * @param menuHeight
     * @returns {number|*}
     */
    let getMenuTopCoordinate = (e, menuHeight) => {
        let mouseHeight = e.pageY;
        if(mouseHeight + menuHeight > window.innerHeight && menuHeight < mouseHeight){
            // opening menu would pass the bottom of the page
            mouseHeight -= menuHeight;
        }
        return {
            top: mouseHeight
        };
    };

    /**
     * render context menu template for maps
     * @returns {*}
     */
    let renderMapContextMenu = () => {
        let moduleData = {
            id: config.mapContextMenuId,
            items: [
                {icon: 'fa-plus', action: 'add_system', text: '시스템 추가'},
                {icon: 'fa-object-ungroup', action: 'select_all', text: '모두 선택'},
                {icon: 'fa-filter', action: 'filter_scope', text: '범위 필터', subitems: [
                        {subIcon: '', subAction: 'filter_wh', subText: '웜홀'},
                        {subIcon: '', subAction: 'filter_stargate', subText: '스타게이트'},
                        {subIcon: '', subAction: 'filter_jumpbridge', subText: '점프브릿지'},
                        {subIcon: '', subAction: 'filter_abyssal', subText: '어비설'}
                    ]},
                {icon: 'fa-sitemap', action: 'map', text: '맵', subitems: [
                        {subIcon: 'fa-edit', subAction: 'map_edit', subText: '맵 편집'},
                        {subIcon: 'fa-street-view', subAction: 'map_info', subText: '맵 정보'},
                    ]},
                {divider: true, action: 'delete_systems'},
                {icon: 'fa-trash', action: 'delete_systems', text: '시스템 삭제'}
            ]
        };

        return Render.render('modules/contextmenu', moduleData);
    };

    /**
     * render context menu template for connections
     * @returns {*}
     */
    let renderConnectionContextMenu = () => {
        let moduleData = {
            id: config.connectionContextMenuId,
            items: [
                {icon: 'fa-hourglass-end', action: 'wh_eol', text: 'EOL 토글'},
                {icon: 'fa-exclamation-triangle', action: 'preserve_mass', text: '질량 보존'},
                {icon: 'fa-reply fa-rotate-180', action: 'change_status', text: '질량 상태', subitems: [
                        {subIcon: 'fa-circle', subIconClass: 'txt-color txt-color-gray', subAction: 'status_fresh', subText: '1단계 (정상)'},
                        {subIcon: 'fa-circle', subIconClass: 'txt-color txt-color-orange', subAction: 'status_reduced', subText: '2단계 (수축)'},
                        {subIcon: 'fa-circle', subIconClass: 'txt-color txt-color-redDark', subAction: 'status_critical', subText: '3단계 (위험)'}

                    ]},
                {icon: 'fa-reply fa-rotate-180', action: 'wh_jump_mass_change', text: '함선 크기', subitems: [
                        {subIcon: 'fa-char', subChar: 'S', subAction: 'wh_jump_mass_s', subText: '소형선'},
                        {subIcon: 'fa-char', subChar: 'M', subAction: 'wh_jump_mass_m', subText: '중형선'},
                        {subIcon: 'fa-char', subChar: 'L', subAction: 'wh_jump_mass_l', subText: '대형선'},
                        {subIcon: 'fa-char', subChar: 'XL', subAction: 'wh_jump_mass_xl', subText: '캐피탈'}

                    ]},
                {icon: 'fa-crosshairs', action: 'change_scope', text: '범위 변경', subitems: [
                        {subIcon: 'fa-minus-circle', subIconClass: '', subAction: 'scope_wh', subText: '웜홀'},
                        {subIcon: 'fa-minus-circle', subIconClass: 'txt-color txt-color-indigoDarkest', subAction: 'scope_stargate', subText: '스타게이트'},
                        {subIcon: 'fa-minus-circle', subIconClass: 'txt-color txt-color-tealLighter', subAction: 'scope_jumpbridge', subText: '점프브릿지'}

                    ]},
                {divider: true, action: 'separator'} ,
                {icon: 'fa-unlink', action: 'delete_connection', text: '연결 끊기'}
            ]
        };

        return Render.render('modules/contextmenu', moduleData);
    };

    /**
     * render context menu template for endpoints
     * @returns {*}
     */
    let renderEndpointContextMenu = () => {
        let moduleData = {
            id: config.endpointContextMenuId,
            items: [
                {icon: 'fa-globe', action: 'bubble', text: '버블 있음'}
            ]
        };

        return Render.render('modules/contextmenu', moduleData);
    };

    /**
     * render context menu template for systems
     * @param systemStatusData
     * @returns {*}
     */
    let renderSystemContextMenu = systemStatusData => {
        let statusData = [];
        for(let [statusName, data] of Object.entries(systemStatusData)){
            statusData.push({
                subIcon:        'fa-tag',
                subIconClass:   data.class,
                subAction:      'change_status_' + statusName,
                subText:        data.label
            });
        }

        let moduleData = {
            id: config.systemContextMenuId,
            items: [
                {icon: 'fa-plus', action: 'add_system', text: '시스템 추가'},
                {icon: 'fa-lock', action: 'lock_system', text: '시스템 잠금/해제'},
                {icon: 'fa-volume-up', action: 'set_rally', text: '집결지 설정'},
                {icon: 'fa-tags', text: '상태 설정', subitems: statusData},
                {icon: 'fa-route', action: 'find_route', text: '경로 찾기'},
                {icon: 'fa-object-group', action: 'select_connections', text: '연결 선택'},
                {icon: 'fa-reply fa-rotate-180', text: '웨이포인트', subitems: [
                        {subIcon: 'fa-flag', subAction: 'set_destination', subText: '목적지 설정'},
                        {subDivider: true, action: ''},
                        {subIcon: 'fa-step-backward', subAction: 'add_first_waypoint', subText: '웨이포인트 추가 [시작]'},
                        {subIcon: 'fa-step-forward', subAction: 'add_last_waypoint', subText: '웨이포인트 추가 [종료]'}
                    ]},
                {divider: true, action: 'delete_system'},
                {icon: 'fa-trash', action: 'delete_system', text: '시스템 삭제'}
            ]
        };

        return Render.render('modules/contextmenu', moduleData);
    };

    /**
     * prepare (hide/activate/disable) some menu options
     * @param menuElement
     * @param hiddenOptions
     * @param activeOptions
     * @param disabledOptions
     * @returns {*}
     */
    let prepareMenu = (menuElement, hiddenOptions, activeOptions, disabledOptions) => {
        let menuLiElements = menuElement.find('li');

        // reset all menu entries
        menuLiElements.removeClass('active').removeClass('disabled').show();

        // hide specific menu entries
        for(let action of hiddenOptions){
            menuElement.find('li[data-action="' + action + '"]').hide();
        }

        //set active specific menu entries
        for(let action of activeOptions){
            menuElement.find('li[data-action="' + action + '"]').addClass('active');
        }

        //disable specific menu entries
        for(let action of disabledOptions){
            menuElement.find('li[data-action="' + action + '"]').addClass('disabled');
        }

        return menuElement;
    };

    /**
     * close all context menus (map, connection,...)
     * @param excludeMenu
     */
    let closeMenus = excludeMenu => {
        let allMenus = $('.' + config.contextMenuClass + '[role="menu"][style*="display: block"]');
        if(excludeMenu){
            allMenus = allMenus.not(excludeMenu);
        }

        allMenus.velocity(config.animationOutType, {
            duration: config.animationOutDuration
        });
    };

    /**
     * open menu handler
     * @param menuConfig
     * @param e
     * @param context
     */
    let openMenu = (menuConfig, e, context) => {
        let menuElement = $('#' + menuConfig.id);

        // close all other context menus
        closeMenus(menuElement);

        // remove menu list click event
        // -> required in case the close handler could not remove them properly
        // -> this happens if menu re-opens without closing (2x right click)
        menuElement.off('click.contextMenuSelect', 'li');

        // hide/activate/disable
        menuElement = prepareMenu(menuElement, menuConfig.hidden, menuConfig.active, menuConfig.disabled);

        let {left, openSubLeft} = getMenuLeftCoordinate(e, menuElement.width());
        let {top} = getMenuTopCoordinate(e, menuElement.height());

        menuElement.toggleClass(config.subMenuLeftClass, openSubLeft).css({
            position: 'absolute',
            left: left,
            top: top
        }).velocity(config.animationInType, {
            duration: config.animationInDuration,
            complete: function(){
                context = {
                    original: {
                        event: e,
                        context: context,
                    },
                    selectCallback: menuConfig.selectCallback
                };

                $(this).one('click.contextMenuSelect', 'li', context, selectHandler);
            }
        });
    };

    /**
     * menu item select handler
     * @param e
     */
    let selectHandler = e => {
        if(e.data.selectCallback){
            e.data.selectCallback(
                $(e.currentTarget).attr('data-action'),
                e.data.original.context.component,
                e.data.original.event
            );
        }
    };

    /**
     * default config (skeleton) for valid context menu configuration
     * @returns {{hidden: [], active: [], disabled: [], id: string, selectCallback: null}}
     */
    let defaultMenuOptionConfig = () => ({
        'id': '',
        'selectCallback': null,
        'hidden': [],
        'active': [],
        'disabled': []
    });

    return {
        config: config,
        defaultMenuOptionConfig: defaultMenuOptionConfig,
        renderMapContextMenu: renderMapContextMenu,
        renderConnectionContextMenu: renderConnectionContextMenu,
        renderEndpointContextMenu: renderEndpointContextMenu,
        renderSystemContextMenu: renderSystemContextMenu,
        openMenu: openMenu,
        closeMenus: closeMenus
    };
});