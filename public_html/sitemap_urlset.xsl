<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="2.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
				xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
				xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
				xmlns:xhtml="http://www.w3.org/1999/xhtml">
	<xsl:output method="html" indent="yes" encoding="UTF-8"/>
	<xsl:template match="/">
		<html lang="ru-RU">
		<head>
			<meta charset="UTF-8"/>
			<title>Карта сайта VIGLING</title>
			<link rel="stylesheet" href="https://vigling.ru/media/com_jlsitemap/css/sitemap.min.css"/>
		</head>
		<body>
		<div class="container">
			<h1>
				Карта сайта VIGLING			</h1>
			<p class="description">
				Карта сайта содержит <xsl:value-of select="count(sitemap:urlset/sitemap:url)"/> ссылок			</p>
			<xsl:apply-templates/>
			<div class="center muted">
				Карта сайта сгенирирована компонентом JL Sitemap			</div>
							<div class="center muted">
					2022-02-16 22:12:18				</div>
					</div>
		</body>
		</html>
	</xsl:template>
	<xsl:template match="sitemap:urlset">
		<table>
			<thead>
			<tr>
				<th class="center" width="1%">#</th>
				<th>Ссылка</th>
				<th>Частота изменений</th>
				<th>Приоритет</th>
				<th>Последние изменения</th>
			</tr>
			</thead>
			<tbody>
			<xsl:for-each select="sitemap:url">
				<xsl:variable name="loc">
					<xsl:value-of select="sitemap:loc"/>
				</xsl:variable>
				<tr>
					<td>
						<xsl:value-of select="position()"/>
					</td>
					<td>
						<div>
							<a href="{$loc}">
								<xsl:value-of select="sitemap:loc"/>
							</a>
						</div>
						<div class="additions">
							<xsl:if test="xhtml:link">
								<xsl:apply-templates select="xhtml:link"/>
							</xsl:if>
							<xsl:if test="image:image">
								<xsl:apply-templates select="image:image"/>
							</xsl:if>
						</div>
					</td>
					<td>
						<xsl:value-of select="sitemap:changefreq"/>
					</td>
					<td>
						<xsl:value-of select="sitemap:priority"/>
					</td>
					<td class="nowrap">
						<xsl:value-of select="sitemap:lastmod"/>
					</td>
				</tr>
			</xsl:for-each>
			</tbody>
		</table>
	</xsl:template>
	<xsl:template match="xhtml:link">
		<xsl:variable name="altloc">
			<xsl:value-of select="@href"/>
		</xsl:variable>
		<xsl:if test="@hreflang">
			<div class="item">
				<a href="{$altloc}" class="alternate" target="_blank">
					<xsl:value-of select="@hreflang"/>
				</a>
			</div>
		</xsl:if>
		<xsl:apply-templates/>
	</xsl:template>
	<xsl:template match="image:image">
		<xsl:variable name="loc">
			<xsl:value-of select="image:loc"/>
		</xsl:variable>
		<div class="item">
			<a href="{$loc}" class="image" title="{$loc}" target="_blank">
				<svg width="20" height="20" viewBox="0 0 20 20"
					 xmlns="http://www.w3.org/2000/svg" data-svg="image">
					<rect fill="none" stroke="#1e87f0" x=".5" y="2.5" width="19"
						  height="15"></rect>
					<polyline fill="none" stroke="#1e87f0" stroke-width="1.01"
							  points="4,13 8,9 13,14"></polyline>
					<polyline fill="none" stroke="#1e87f0" stroke-width="1.01"
							  points="11,12 12.5,10.5 16,14"></polyline>
				</svg>
			</a>
		</div>
	</xsl:template>
</xsl:stylesheet>