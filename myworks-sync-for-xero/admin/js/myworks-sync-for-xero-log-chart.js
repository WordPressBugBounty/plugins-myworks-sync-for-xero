(function($) {
	'use strict';

	/**
	 * Chart.js Dashboard Log Chart
	 * Renders sync activity chart with period selection
	 */

	$(document).ready(function() {
		// Check if chart data is available
		if (typeof mwxsChartData === 'undefined') {
			return;
		}

		// Period chooser button handler
		$('.btn-period-chooser button').click(function() {
			$('.btn-period-chooser button').removeClass('active');
			$(this).addClass('active');
			var period = $(this).data('period');
			mw_wc_qbo_sync_refresh_log_chart(period);
		});

		// Build chart datasets from localized data
		var lineData = {
			labels: mwxsChartData.labels,
			datasets: [
				{
					label: "Customer",
					backgroundColor: "rgba(" + mwxsChartData.client.bgColor + ")",
					borderColor: "rgba(" + mwxsChartData.client.borderColor + ")",
					pointBackgroundColor: "rgba(" + mwxsChartData.client.pointBgColor + ")",
					pointBorderColor: mwxsChartData.client.pointBorderColor,
					fill: true,
					data: mwxsChartData.customer
				},
				{
					label: "Order",
					backgroundColor: "rgba(93,197,96,0.5)",
					borderColor: "rgba(93,197,96,1)",
					pointBackgroundColor: "rgba(93,197,96,1)",
					pointBorderColor: "#fff",
					fill: true,
					data: mwxsChartData.order
				},
				{
					label: "Payment",
					backgroundColor: "rgba(" + mwxsChartData.payment.bgColor + ")",
					borderColor: "rgba(" + mwxsChartData.payment.borderColor + ")",
					pointBackgroundColor: "rgba(" + mwxsChartData.payment.pointBgColor + ")",
					pointBorderColor: mwxsChartData.payment.pointBorderColor,
					fill: true,
					data: mwxsChartData.payment.data
				},
				{
					label: "Product / Variation",
					backgroundColor: "rgba(" + mwxsChartData.product.bgColor + ")",
					borderColor: "rgba(" + mwxsChartData.product.borderColor + ")",
					pointBackgroundColor: "rgba(" + mwxsChartData.product.pointBgColor + ")",
					pointBorderColor: mwxsChartData.product.pointBorderColor,
					fill: true,
					data: mwxsChartData.product.data
				}
			]
		};

		// Set canvas dimensions
		var canvas = document.getElementById("Chart_MWQS");
		var parent = document.getElementById('ChartParent_MWQS');

		if (!canvas || !parent) {
			return;
		}

		canvas.width = parent.offsetWidth;
		canvas.height = parent.offsetHeight;

		var ctx = $("#Chart_MWQS");

		// Chart options
		var options = {
			responsive: true,
			maintainAspectRatio: false,
			scales: {
				y: {
					beginAtZero: true,
					ticks: {
						precision: 0
					}
				}
			},
			elements: {
				line: {
					tension: 0.4  // smooth lines
				}
			}
		};

		// Initialize Chart.js
		new Chart(ctx, {
			type: 'line',
			data: lineData,
			options: options
		});
	});

})(jQuery);
