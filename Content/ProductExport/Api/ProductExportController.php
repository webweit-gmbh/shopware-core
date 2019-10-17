<?php declare(strict_types=1);

namespace Shopware\Core\Content\ProductExport\Api;

use Monolog\Logger;
use Shopware\Core\Content\ProductExport\Error\Error;
use Shopware\Core\Content\ProductExport\Event\ProductExportLoggingEvent;
use Shopware\Core\Content\ProductExport\Exception\RenderFooterException;
use Shopware\Core\Content\ProductExport\Exception\RenderHeaderException;
use Shopware\Core\Content\ProductExport\Exception\RenderProductException;
use Shopware\Core\Content\ProductExport\Exception\SalesChannelDomainNotFoundException;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Content\ProductExport\Service\ProductExportGeneratorInterface;
use Shopware\Core\Content\ProductExport\Struct\ExportBehavior;
use Shopware\Core\Content\ProductExport\Struct\ProductExportResult;
use Shopware\Core\Content\ProductExport\Template\TemplateInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"api"})
 */
class ProductExportController extends AbstractController
{
    /** @var EntityRepositoryInterface */
    private $salesChannelDomainRepository;

    /** @var ProductExportGeneratorInterface */
    private $productExportGenerator;

    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    /** @var iterable|TemplateInterface[] */
    private $templates;

    public function __construct(
        EntityRepositoryInterface $salesChannelDomainRepository,
        ProductExportGeneratorInterface $productExportGenerator,
        EventDispatcherInterface $eventDispatcher,
        iterable $templates
    ) {
        $this->salesChannelDomainRepository = $salesChannelDomainRepository;
        $this->productExportGenerator = $productExportGenerator;
        $this->eventDispatcher = $eventDispatcher;
        $this->templates = $templates;
    }

    /**
     * @Route("/api/v{version}/_action/product-export/validate", name="api.action.product_export.validate",
     *                                                           methods={"POST"})
     *
     * @throws RenderHeaderException
     * @throws RenderProductException
     * @throws RenderFooterException
     */
    public function validate(RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $result = $this->generateExportPreview($dataBag, $context);

        if ($result->hasErrors()) {
            $errors = $result->getErrors();
            $errorMessages = array_merge(
                ...array_map(
                    function (Error $error) {
                        return $error->getErrorMessages();
                    },
                    $errors
                )
            );

            return new JsonResponse(
                [
                    'content' => mb_convert_encoding(
                        $result->getContent(),
                        'UTF-8',
                        $dataBag->get('encoding')
                    ),
                    'errors' => $errorMessages,
                ]
            );
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @Route("/api/v{version}/_action/product-export/preview", name="api.action.product_export.preview", methods={"POST"})
     *
     * @throws RenderHeaderException
     * @throws RenderProductException
     * @throws RenderFooterException
     */
    public function preview(RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $result = $this->generateExportPreview($dataBag, $context);

        if ($result->hasErrors()) {
            $errors = $result->getErrors();
            $errorMessages = array_merge(
                ...array_map(
                    function (Error $error) {
                        return $error->getErrorMessages();
                    },
                    $errors
                )
            );
        }

        return new JsonResponse(
            [
                'content' => mb_convert_encoding(
                    $result->getContent(),
                    'UTF-8',
                    $dataBag->get('encoding')
                ),
                'errors' => $errorMessages ?? [],
            ]
        );
    }

    /**
     * @Route("/api/v{version}/_action/product-export/templates", name="api.action.product_export.templates", methods={"GET"})
     */
    public function listTemplates(RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $templates = [];
        foreach (iterator_to_array($this->templates) as $template) {
            $templates[$template->getName()] = $template;
        }

        return new JsonResponse([
            'templates' => $templates,
        ]);
    }

    private function createEntity(RequestDataBag $dataBag): ProductExportEntity
    {
        $entity = new ProductExportEntity();

        $entity->setId('');
        $entity->setHeaderTemplate($dataBag->get('header_template' ?? ''));
        $entity->setBodyTemplate($dataBag->get('body_template') ?? '');
        $entity->setFooterTemplate($dataBag->get('footer_template') ?? '');
        $entity->setProductStreamId($dataBag->get('product_stream_id'));
        $entity->setIncludeVariants($dataBag->get('include_variants'));
        $entity->setEncoding($dataBag->get('encoding'));
        $entity->setFileFormat($dataBag->get('file_format'));
        $entity->setFileName($dataBag->get('file_name'));
        $entity->setAccessKey($dataBag->get('access_key'));
        $entity->setSalesChannelId($dataBag->get('sales_channel_id'));
        $entity->setSalesChannelDomainId($dataBag->get('sales_channel_domain_id'));

        return $entity;
    }

    private function generateExportPreview(RequestDataBag $dataBag, Context $context): ProductExportResult
    {
        $salesChannelDomainId = $dataBag->get('sales_channel_domain_id');

        $criteria = (new Criteria([$salesChannelDomainId]))
            ->addAssociation('language.locale')
            ->addAssociation('salesChannel');
        $salesChannelDomain = $this->salesChannelDomainRepository->search(
            $criteria,
            $context
        )->get($salesChannelDomainId);

        if (!($salesChannelDomain instanceof SalesChannelDomainEntity)) {
            $salesChannelDomainNotFoundException = new SalesChannelDomainNotFoundException($salesChannelDomainId);
            $loggingEvent = new ProductExportLoggingEvent(
                $context,
                $salesChannelDomainNotFoundException->getMessage(),
                Logger::ERROR,
                $salesChannelDomainNotFoundException
            );
            $this->eventDispatcher->dispatch($loggingEvent);
            throw $salesChannelDomainNotFoundException;
        }

        $productExportEntity = $this->createEntity($dataBag);
        $productExportEntity->setSalesChannelDomain($salesChannelDomain);
        $productExportEntity->setStorefrontSalesChannelId($salesChannelDomain->getSalesChannelId());

        $exportBehavior = new ExportBehavior(true, true, true);

        return $this->productExportGenerator->generate($productExportEntity, $exportBehavior);
    }
}
