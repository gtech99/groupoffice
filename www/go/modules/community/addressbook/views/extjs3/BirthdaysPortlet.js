go.modules.community.addressbook.BirthdaysPortlet = function (config) {
	if (!config) {
		config = {};
	}
	config.id = 'su-birthdays-grid';
	var arAddressBookIds = [];
	Ext.iterate(go.User.birthdayPortletAddressBooks, function (item, idx, o) {
		arAddressBookIds.push(item.addressBookId);
	}, this);

	config.addressBookIds = arAddressBookIds;

	config.store = new go.data.GroupingStore({
		autoDestroy: true,
		fields: [
			'id',
			'addressBookId',
			'name',
			'birthday',
			'age',
			'photoBlobId',
			'addressBook'
		],
		entityStore: "Contact",
		autoLoad: false,
		sortInfo: {
			field: 'birthday',
			direction: 'ASC'
		},
		groupField: 'addressBook',
		remoteGroup: true,
		remoteSort: true
	});

	config.store.setFilter('addressBookIds', {addressBookIds: config.addressBookIds})
		.setFilter('isOrganisation', {isOrganization: false})
		.setFilter('birthday', {birthday: '< 30 days'});
	config.store.load().then(function (result) {
		this.store.data = result;
		if (this.rendered) {
			this.ownerCt.ownerCt.ownerCt.doLayout();
		}
	}, this);

	config.paging = false,
		config.autoExpandColumn = 'birthday-portlet-name-col';
	config.autoExpandMax = 2500;
	config.enableColumnHide = false;
	config.enableColumnMove = false;
	config.columns = [
		{
			header: '',
			dataIndex: 'photoBlobId',

			renderer: function (value, metaData, record) {
				return go.util.avatar(record.get('name'), record.data.photoBlobId, null);
			}
		}, {
			id: 'birthday-portlet-name-col',
			header: t("Name"),
			dataIndex: 'name',
			sortable: false,
			renderer: function (value, metaData, record) {
				return '<a href="#contact/' + record.json.id + '">' + value + '</a>';
			}
		}, {
			header: t("Address book", "addressbook"),
			dataIndex: 'addressBook',
			sortable: true
		}, {
			header: t("Birthday", "addressbook"),
			dataIndex: 'birthday',
			width: 100,
			sortable: false,
			renderer: function (value, metaData, record) {
				return go.util.Format.date(value);
			}
		}, {
			header: t("Age"),
			dataIndex: 'age',
			sortable: false,
			width: 100
		}];
	config.view = new Ext.grid.GroupingView({
		scrollOffset: 2,
		hideGroupedColumn: true
	});
	config.sm = new Ext.grid.RowSelectionModel();
	config.loadMask = true;
	config.autoHeight = true;

	go.modules.community.addressbook.BirthdaysPortlet.superclass.constructor.call(this, config);

};

Ext.extend(go.modules.community.addressbook.BirthdaysPortlet, go.grid.GridPanel, {

	saveListenerAdded: false,

	afterRender: function () {
		go.modules.community.addressbook.BirthdaysPortlet.superclass.afterRender.call(this);

		Ext.TaskMgr.start({
			run: function () {
				this.store.load();
			},
			scope: this,
			interval: 960000
		});
	}
});


GO.mainLayout.onReady(function () {
	if (go.Modules.isAvailable("legacy", "summary") && go.Modules.isAvailable("community", "addressbook")) {
		var birthdaysGrid = new go.modules.community.addressbook.BirthdaysPortlet();

		GO.summary.portlets['portlet-birthdays'] = new GO.summary.Portlet({
			id: 'portlet-birthdays',
			iconCls: 'ic-cake',
			title: t("Upcoming birthdays", "addressbook"),
			layout: 'fit',
			tools: [{
				id: 'gear',
				handler: function () {
					var dlg = new go.modules.community.addressbook.BirthdaysPortletSettingsDialog({
						listeners: {
							hide: function () {
								birthdaysGrid.store.reload();
							},
							scope: this
						}
					})
					dlg.load(go.User.id).show();
				}
			}, {
				id: 'close',
				handler: function (e, target, panel) {
					panel.removePortlet();
				}
			}],
			items: birthdaysGrid,
			autoHeight: true
		});
	}
});