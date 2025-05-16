/**
 * Energy Analytics Charts
 *
 * Chart.js implementation for Energy Analytics dashboard
 *
 * @package Energy_Analytics
 */

/**
 * Initialize charts on the dashboard
 */
function eaChartsInit() {
    // Wait for document to be ready
    jQuery(document).ready(function($) {
        // Fetch the data from our REST API endpoint
        $.ajax({
            url: eaCharts.apiUrl,
            method: 'GET',
            headers: {
                'X-WP-Nonce': eaCharts.restNonce
            },
            beforeSend: function() {
                // Show loading indicator
                $('.ea-chart-container').addClass('loading');
            },
            success: function(response) {
                // Process the data and initialize charts
                initializeCharts(response);
            },
            error: function(xhr) {
                // Handle errors
                console.error('Energy Analytics API Error:', xhr.responseText);
                $('.ea-chart-container').removeClass('loading').addClass('error');
                $('.ea-chart-container').html('<div class="ea-chart-error">Error loading chart data.</div>');
            }
        });

        /**
         * Initialize all charts with the API response data
         * 
         * @param {Object} data The API response data
         */
        function initializeCharts(data) {
            // Remove loading indicators
            $('.ea-chart-container').removeClass('loading');

            // Initialize Energy Usage Over Time chart (Line Chart)
            initializeTimeSeriesChart(data.time_series);

            // Initialize Energy Distribution Chart (Pie Chart)
            initializeDistributionChart(data.by_source);

            // Initialize Energy Source Chart (Bar Chart)
            initializeSourceChart(data.by_source);

            // Initialize Efficiency Metrics Chart (Radar Chart)
            initializeEfficiencyChart(data.efficiency);
        }

        /**
         * Initialize the time series chart
         * 
         * @param {Array} timeSeriesData Array of time series data points
         */
        function initializeTimeSeriesChart(timeSeriesData) {
            // Prepare the data
            const labels = [];
            const values = [];

            // Extract labels and values from the time series data
            timeSeriesData.forEach(function(item) {
                labels.push(item.label);
                values.push(parseFloat(item.value.replace(/,/g, '')));
            });

            // Get the canvas element
            const ctx = document.getElementById('energyUsageChart').getContext('2d');

            // Create the chart
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Energy Usage',
                        data: values,
                        borderColor: eaCharts.colors.primary,
                        backgroundColor: hexToRgba(eaCharts.colors.primary, 0.1),
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Energy Consumption'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Period'
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Energy: ' + context.parsed.y.toFixed(2);
                                }
                            }
                        },
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: false,
                            text: 'Energy Usage Over Time'
                        }
                    }
                }
            });
        }

        /**
         * Initialize the distribution chart (pie)
         * 
         * @param {Array} sourceData Array of energy source data
         */
        function initializeDistributionChart(sourceData) {
            // Prepare the data
            const labels = [];
            const values = [];
            const backgroundColors = [
                eaCharts.colors.primary,
                eaCharts.colors.secondary,
                eaCharts.colors.tertiary,
                eaCharts.colors.quaternary,
                '#9c27b0',
                '#ff9800',
                '#795548'
            ];

            // Extract labels and values from the source data
            sourceData.forEach(function(item, index) {
                labels.push(item.label);
                values.push(parseFloat(item.percentage));
            });

            // Get the canvas element
            const ctx = document.getElementById('energyDistributionChart').getContext('2d');

            // Create the chart
            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: backgroundColors.slice(0, labels.length),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed + '%';
                                }
                            }
                        },
                        legend: {
                            position: 'right',
                        }
                    }
                }
            });
        }

        /**
         * Initialize the energy source chart (bar)
         * 
         * @param {Array} sourceData Array of energy source data
         */
        function initializeSourceChart(sourceData) {
            // Prepare the data
            const labels = [];
            const values = [];

            // Extract labels and values from the source data
            sourceData.forEach(function(item) {
                labels.push(item.label);
                values.push(parseFloat(item.value.replace(/,/g, '')));
            });

            // Get the canvas element
            const ctx = document.getElementById('energySourceChart').getContext('2d');

            // Create the chart
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Consumption',
                        data: values,
                        backgroundColor: hexToRgba(eaCharts.colors.secondary, 0.8),
                        borderColor: eaCharts.colors.secondary,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Consumption'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Energy Source'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    }
                }
            });
        }

        /**
         * Initialize the efficiency metrics chart (radar)
         * 
         * @param {Array} efficiencyData Array of efficiency metrics
         */
        function initializeEfficiencyChart(efficiencyData) {
            // Prepare the data
            const labels = [];
            const values = [];
            const trends = [];

            // Extract values and normalize them to a 0-100 scale for the radar chart
            efficiencyData.forEach(function(item) {
                labels.push(item.label);
                
                // Parse the value - handle percentage or absolute values
                let value = 0;
                if (item.value.includes('%')) {
                    value = parseFloat(item.value);
                } else if (item.value.includes('kg')) {
                    // For carbon footprint (lower is better), convert to a 0-100 scale
                    // Assuming 300kg is 0% and 0kg is 100%
                    const carbon = parseFloat(item.value);
                    value = Math.max(0, Math.min(100, (300 - carbon) / 3)); 
                } else {
                    // Default case - just try to extract a number
                    value = parseFloat(item.value) || 50; // Default to 50 if we can't parse
                }
                
                values.push(value);
                trends.push(item.trend);
            });

            // Get the canvas element
            const ctx = document.getElementById('efficiencyMetricsChart').getContext('2d');

            // Create the chart
            new Chart(ctx, {
                type: 'radar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Current Performance',
                        data: values,
                        backgroundColor: hexToRgba(eaCharts.colors.tertiary, 0.2),
                        borderColor: eaCharts.colors.tertiary,
                        borderWidth: 2,
                        pointBackgroundColor: eaCharts.colors.tertiary
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            beginAtZero: true,
                            min: 0,
                            max: 100,
                            ticks: {
                                stepSize: 20
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const index = context.dataIndex;
                                    const trend = trends[index];
                                    const originalValue = efficiencyData[index].value;
                                    return context.label + ': ' + originalValue + ' (' + (trend === 'up' ? '↑' : '↓') + ')';
                                }
                            }
                        },
                        legend: {
                            position: 'top',
                        }
                    }
                }
            });
        }

        /**
         * Convert hex color to rgba
         * 
         * @param {string} hex Hex color code
         * @param {number} alpha Alpha transparency value
         * @return {string} RGBA color string
         */
        function hexToRgba(hex, alpha) {
            const r = parseInt(hex.slice(1, 3), 16);
            const g = parseInt(hex.slice(3, 5), 16);
            const b = parseInt(hex.slice(5, 7), 16);
            return `rgba(${r}, ${g}, ${b}, ${alpha})`;
        }
    });
}

// Initialize charts when script is loaded in non-admin contexts
if (typeof window !== 'undefined' && !window.wp && !window.wp.blocks) {
    eaChartsInit();
}
